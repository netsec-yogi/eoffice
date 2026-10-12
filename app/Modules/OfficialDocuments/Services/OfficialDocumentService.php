<?php

namespace App\Modules\OfficialDocuments\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\OfficialDocuments\Jobs\GenerateOfficialDocument;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplateVersion;
use App\Modules\OfficialDocuments\Models\WorkflowDocumentOutput;
use App\Modules\OfficialDocuments\Pdf\GenerationException;
use App\Modules\OfficialDocuments\Pdf\PdfChecker;
use App\Modules\OfficialDocuments\Pdf\PdfRenderer;
use App\Modules\OfficialDocuments\Pdf\TemplateInspector;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\WorkflowAction;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Rules\RuleEvaluator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The life of an official document (DOC-004):
 *  submission     -> a row per configured output, template version fixed;
 *  final approval -> eligibility checked against the instance itself, then queued;
 *  generation     -> render, check, store, release (idempotent, retryable);
 *  rejection etc. -> not generated.
 * A failed generation never touches the approval: the request stays approved.
 */
class OfficialDocumentService
{
    public function __construct(
        private DocumentData $data,
        private PdfRenderer $renderer,
        private PdfChecker $checker,
        private DocumentStore $documents,
        private RuleEvaluator $rules,
        private NotificationDispatcher $notifications,
        private AuditLogger $audit,
    ) {}

    /** At submission: one row per output, with the template version this request will use. */
    public function prepare(WorkflowInstance $instance): void
    {
        foreach ($this->outputsFor($instance) as $output) {
            $this->row($instance, $output);
        }
    }

    /** Called after the engine has committed the end of a request. */
    public function finished(WorkflowInstance $instance): void
    {
        $instance->refresh();
        $approved = $instance->status === 'completed' && $instance->outcome === 'approved';
        foreach ($this->outputsFor($instance) as $output) {
            $this->row($instance, $output);
        }
        $rows = OfficialDocument::query()->with('output')->where('workflow_instance_id', $instance->id)->where('status', 'waiting_approval')->get();
        foreach ($rows as $doc) {
            if (! $approved) {
                $this->setStatus($doc, 'not_generated', ['error_code' => null]);

                continue;
            }
            if (! $this->rules->passes($doc->output->condition, ['form' => $instance->data ?? []] + ($instance->data ?? []))) {
                $this->setStatus($doc, 'not_generated', ['error_code' => 'condition_not_met']);

                continue;
            }
            $this->queue($doc, null, 'official_document.requested');
        }
    }

    public function generate(int $id): void
    {
        // Claim the row: only a queued or failed document can start; a
        // duplicate job finds it generating or generated and stops.
        $doc = DB::transaction(function () use ($id) {
            $doc = OfficialDocument::query()->lockForUpdate()->find($id);
            if (! $doc || ! in_array($doc->status, ['queued', 'failed'], true)) {
                return null;
            }
            $doc->forceFill(['status' => 'generating', 'attempts' => $doc->attempts + 1, 'error_code' => null, 'error_detail' => null])->save();

            return $doc;
        });
        if (! $doc) {
            return;
        }
        $this->audit->record('official_document.generation_started', $doc, ['attempt' => $doc->attempts], actor: null);

        try {
            $this->produce($doc->load('instance.definition', 'output.template', 'templateVersion'));
        } catch (GenerationException $e) {
            $this->fail($doc, $e->reason, $e->getMessage());
        }
        // Anything else (storage, database) propagates: the queue retries and
        // GenerateOfficialDocument::failed() records the final failure.
    }

    public function fail(OfficialDocument $doc, string $code, string $detail): void
    {
        $doc->refresh();
        if (in_array($doc->status, ['generated', 'awaiting_signature'], true)) {
            return;
        }
        $this->setStatus($doc, 'failed', ['error_code' => $code, 'error_detail' => mb_substr($detail, 0, 2000)]);
        $this->audit->record('official_document.generation_failed', $doc, ['code' => $code, 'attempt' => $doc->attempts], outcome: 'failure', actor: null);
        $admins = User::permission(Permissions::OFFICIAL_DOCUMENTS_MANAGE)->where('is_active', true)->get();
        $this->notify('official_document.failed', $admins, $doc, "official:{$doc->id}:failed:{$doc->attempts}", route('admin.official-documents.index', ['status' => 'failed'], false));
    }

    public function retry(OfficialDocument $doc, User $by): void
    {
        if ($doc->status !== 'failed') {
            throw new WorkflowException('Only a failed generation can be retried.', 409);
        }
        $this->queue($doc, $by, 'official_document.retry_requested');
    }

    /**
     * Authorised reissue (§15): the earlier document is kept and marked
     * superseded; the new one is generated from the pinned template version
     * unless the officer explicitly chooses the current published version.
     */
    public function regenerate(OfficialDocument $doc, User $by, string $reason, bool $useCurrentVersion = false): OfficialDocument
    {
        $doc->loadMissing('output.template', 'instance');
        if ($doc->output->regeneration !== 'authorised') {
            throw new WorkflowException('This document may not be reissued under its configuration.', 403);
        }
        if (! in_array($doc->status, ['generated', 'awaiting_signature'], true)) {
            throw new WorkflowException('Only an issued document can be reissued; retry failed ones instead.', 409);
        }
        if (trim($reason) === '') {
            throw new WorkflowException('Record the reason for reissuing.', 422, ['reason' => 'Required.']);
        }
        $versionId = $useCurrentVersion ? $doc->output->template->published()->value('id') : $doc->pdf_template_version_id;
        if (! $versionId) {
            throw new WorkflowException('The template has no published version.', 422);
        }

        $new = DB::transaction(function () use ($doc, $by, $reason, $versionId) {
            $old = OfficialDocument::query()->lockForUpdate()->findOrFail($doc->id);
            $old->forceFill(['status' => 'superseded'])->save();
            $new = new OfficialDocument;
            $new->forceFill([
                'uuid' => (string) Str::uuid(), 'workflow_instance_id' => $old->workflow_instance_id, 'workflow_document_output_id' => $old->workflow_document_output_id,
                'pdf_template_version_id' => $versionId, 'document_version' => $old->document_version + 1, 'status' => 'waiting_approval',
                'approved_at' => $old->approved_at, 'supersedes_id' => $old->id, 'regeneration_reason' => $reason, 'requested_by' => $by->id,
            ])->save();
            $this->audit->record('official_document.superseded', $old, ['by' => $new->uuid, 'reason' => $reason], actor: $by);

            return $new;
        });
        $this->queue($new, $by, 'official_document.regeneration_requested', ['reason' => $reason, 'supersedes' => $doc->uuid, 'template_version_id' => $versionId]);

        return $new;
    }

    public function downloadName(OfficialDocument $doc): string
    {
        $doc->loadMissing('output.template', 'instance', 'templateVersion');
        $name = strtr($doc->output->filename_pattern, [
            '{template}' => pathinfo($doc->templateVersion?->original_filename ?? $doc->output->template->name, PATHINFO_FILENAME),
            '{reference}' => (string) $doc->instance->reference, '{version}' => (string) $doc->document_version,
        ]);
        $name = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', str_replace('/', '-', $name)), '._');

        return Str::limit($name ?: 'document', 150, '').($doc->document_version > 1 ? '_v'.$doc->document_version : '').'.pdf';
    }

    // ---------------------------------------------------------------------

    private function produce(OfficialDocument $doc): void
    {
        $instance = $doc->instance;
        // Never trust the event alone: the request itself must be finished and approved,
        // with every required step carrying an approval.
        if ($instance->status !== 'completed' || $instance->outcome !== 'approved') {
            throw new GenerationException('approvals_incomplete', "Request is {$instance->status}; documents are generated only for approved requests.");
        }
        $done = WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('action', 'approved')->pluck('node_key')->unique();
        if ($missing = array_diff($doc->output->required_steps ?? [], $done->all())) {
            throw new GenerationException('approvals_incomplete', 'No approval recorded at: '.implode(', ', $missing).'.');
        }
        $version = $doc->templateVersion ?? throw new GenerationException('template_missing', 'The template "'.$doc->output->template->name.'" had no published version when this request was submitted, and none was assigned since.');
        $working = Storage::disk(config('documents.disk'))->path((string) $version->working_path);
        if (! is_file($working)) {
            throw new GenerationException('template_missing', "Working copy of template version {$version->version} is missing.");
        }

        $context = $this->data->for($instance, ['version' => $doc->document_version, 'template_version' => $version->version]);
        $bytes = $this->renderer->render($working, $version->pages, $version->mapping, $context, [
            'title' => $doc->output->template->name.' '.$instance->reference, 'subject' => 'Official document for '.$instance->reference,
        ]);
        $this->checker->check($bytes, $version->pages);

        $path = tempnam(TemplateInspector::tempDir(), 'doc');
        file_put_contents($path, $bytes);
        try {
            $filename = $this->downloadName($doc);
            $stored = $this->documents->store(new UploadedFile($path, $filename, 'application/pdf', null, true), $doc, $instance->initiator()->firstOrFail(), 'official_document', $doc->output->confidential ? 'confidential' : 'internal');
        } finally {
            @unlink($path);
        }

        $signature = $doc->output->requires_signature;
        DB::transaction(function () use ($doc, $stored, $filename, $signature) {
            $locked = OfficialDocument::query()->lockForUpdate()->findOrFail($doc->id);
            $locked->forceFill([
                'status' => $signature ? 'awaiting_signature' : 'generated', 'document_id' => $stored->id, 'filename' => $filename, 'sha256' => $stored->sha256,
                'generated_at' => now(), 'error_code' => $signature && ! config('signatures.provider') ? 'signature_provider_missing' : null,
            ])->save();
        });
        $doc->refresh();
        $this->audit->record('official_document.generated', $doc, [
            'reference' => $instance->reference, 'template_version' => $version->version, 'document_version' => $doc->document_version,
            'sha256' => $doc->sha256, 'released' => ! $signature,
        ], actor: null);

        if (! $signature) {
            $this->notify('official_document.ready', [$instance->initiator()->firstOrFail()], $doc, "official:{$doc->id}:ready", route('official-documents.index', [], false));
        }
    }

    private function queue(OfficialDocument $doc, ?User $by, string $event, array $context = []): void
    {
        $doc->forceFill(['status' => 'queued', 'approved_at' => $doc->approved_at ?? $doc->instance()->value('completed_at')])->save();
        $this->audit->record($event, $doc, $context + ['instance' => $doc->workflow_instance_id], actor: $by);
        GenerateOfficialDocument::dispatch($doc->id)->afterCommit();
    }

    /** One current row per (instance, output); a duplicate event finds it. */
    private function row(WorkflowInstance $instance, WorkflowDocumentOutput $output): OfficialDocument
    {
        $existing = OfficialDocument::query()->where(['workflow_instance_id' => $instance->id, 'workflow_document_output_id' => $output->id])
            ->whereNotIn('status', ['superseded', 'archived'])->first();
        if ($existing) {
            return $existing;
        }
        $doc = new OfficialDocument;
        $doc->forceFill([
            'uuid' => (string) Str::uuid(), 'workflow_instance_id' => $instance->id, 'workflow_document_output_id' => $output->id,
            'pdf_template_version_id' => PdfTemplateVersion::query()->where('pdf_template_id', $output->pdf_template_id)->where('status', 'published')->value('id'),
            'status' => 'waiting_approval',
        ]);
        try {
            DB::transaction(fn () => $doc->save()); // savepoint: a lost race must not abort the caller's transaction
        } catch (UniqueConstraintViolationException) {
            return OfficialDocument::query()->where(['workflow_instance_id' => $instance->id, 'workflow_document_output_id' => $output->id])->whereNotIn('status', ['superseded', 'archived'])->firstOrFail();
        }

        return $doc;
    }

    private function outputsFor(WorkflowInstance $instance)
    {
        return WorkflowDocumentOutput::query()->where('workflow_definition_id', $instance->workflow_definition_id)->where('is_enabled', true)
            ->whereHas('template', fn ($q) => $q->where('is_active', true))->get();
    }

    private function setStatus(OfficialDocument $doc, string $status, array $extra = []): void
    {
        $doc->forceFill(['status' => $status] + $extra)->save();
    }

    private function notify(string $template, iterable $to, OfficialDocument $doc, string $key, string $link): void
    {
        try {
            $doc->loadMissing('instance', 'output.template');
            $this->notifications->send($template, $to, ['document' => ['name' => $doc->output->template->name, 'reference' => $doc->instance->reference, 'reason' => GenerationException::CODES[$doc->error_code] ?? '']], $key, $link);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
