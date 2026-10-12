<?php

namespace App\Modules\OfficialDocuments\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Identity\Models\User;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Models\PdfTemplateVersion;
use App\Modules\OfficialDocuments\Models\WorkflowDocumentOutput;
use App\Modules\OfficialDocuments\Pdf\TemplateException;
use App\Modules\OfficialDocuments\Pdf\TemplateInspector;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Rules\RuleEvaluator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Official templates: upload, new versions, mapping, publication, bindings (DOC-001..003). */
class TemplateService
{
    public function __construct(
        private DocumentStore $documents,
        private TemplateInspector $inspector,
        private MappingValidator $validator,
        private RuleEvaluator $rules,
        private AuditLogger $audit,
    ) {}

    public function create(array $data, UploadedFile $file, User $by): PdfTemplate
    {
        return DB::transaction(function () use ($data, $file, $by) {
            $template = new PdfTemplate($data);
            $template->forceFill(['created_by' => $by->id, 'is_active' => true])->save();
            $this->audit->record('official_template.created', $template, ['key' => $template->key, 'name' => $template->name], actor: $by);
            $this->addVersion($template, $file, $by, 'First version');

            return $template;
        });
    }

    /** A replacement file: a new draft version, starting from the previous mapping. */
    public function addVersion(PdfTemplate $template, UploadedFile $file, User $by, ?string $note = null): PdfTemplateVersion
    {
        if ($template->draft()->exists()) {
            throw new WorkflowException('This template already has a draft version. Publish or discard it first.', 409);
        }
        $this->documents->validate($file);
        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['file' => 'Upload the official form as a PDF.']);
        }

        return DB::transaction(function () use ($template, $file, $by, $note) {
            $template = PdfTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $previous = PdfTemplateVersion::query()->where('pdf_template_id', $template->id)->orderByDesc('version')->first();
            $original = $this->documents->store($file, null, $by, 'official_template', 'internal');
            $source = Storage::disk($original->disk)->path($original->path);
            $workingPath = 'official-templates/'.$template->id.'/'.$original->uuid.'.pdf';
            Storage::disk($original->disk)->makeDirectory(dirname($workingPath));

            try {
                $info = $this->inspector->inspect($source, Storage::disk($original->disk)->path($workingPath));
            } catch (TemplateException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            $version = new PdfTemplateVersion;
            $version->forceFill([
                'pdf_template_id' => $template->id, 'version' => ($previous?->version ?? 0) + 1, 'status' => 'draft',
                'original_document_id' => $original->id, 'original_filename' => $original->original_name, 'original_sha256' => $original->sha256,
                'working_path' => $workingPath, 'page_count' => count($info['pages']), 'pages' => $info['pages'], 'form_fields' => $info['form_fields'],
                // Carry the mapping forward; fields on pages that no longer exist are dropped and reported.
                'mapping' => array_values(array_filter($previous?->mapping ?? [], fn ($f) => (int) ($f['page'] ?? 0) <= count($info['pages']))),
                'change_note' => $note, 'created_by' => $by->id,
            ])->save();
            $this->documents->attach($original, $version);
            $this->audit->record('official_template.version_uploaded', $version, [
                'template' => $template->key, 'version' => $version->version, 'file' => $original->original_name, 'sha256' => $original->sha256,
                'pages' => $version->page_count, 'form_fields' => count($info['form_fields']),
            ], actor: $by);

            return $version;
        });
    }

    public function saveMapping(PdfTemplateVersion $version, array $mapping, User $by): PdfTemplateVersion
    {
        if (! $version->isDraft()) {
            throw new WorkflowException('Published versions cannot be changed. Upload the form again to start a new version.', 409);
        }
        $mapping = array_values($mapping);
        if ($problems = $this->validator->problems($mapping, $version->pages)) {
            throw new WorkflowException('Fix the mapping: '.$problems[0], 422, ['mapping' => $problems[0]]);
        }
        $before = collect($version->mapping)->keyBy('key');
        $version->forceFill(['mapping' => $mapping])->save();
        $after = collect($mapping)->keyBy('key');
        $this->audit->record('official_template.mapping_saved', $version, [
            'version' => $version->version, 'fields' => $after->count(),
            'added' => $after->keys()->diff($before->keys())->values(), 'removed' => $before->keys()->diff($after->keys())->values(),
        ], actor: $by);

        return $version;
    }

    public function discard(PdfTemplateVersion $version, User $by): void
    {
        if (! $version->isDraft() || $version->version === 1) {
            throw new WorkflowException('Only a draft replacement can be discarded.', 409);
        }
        PdfTemplateVersion::query()->whereKey($version->id)->update(['status' => 'discarded', 'updated_at' => now()]);
        $this->audit->record('official_template.draft_discarded', $version, ['version' => $version->version], actor: $by);
    }

    public function publish(PdfTemplateVersion $version, User $by): PdfTemplateVersion
    {
        return DB::transaction(function () use ($version, $by) {
            $version = PdfTemplateVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (! $version->isDraft()) {
                throw new WorkflowException('This version is already published.', 409);
            }
            if ($version->mapping === []) {
                throw new WorkflowException('Map at least one field before publishing.', 422, ['mapping' => 'No fields mapped.']);
            }
            if ($problems = $this->validator->problems($version->mapping, $version->pages)) {
                throw new WorkflowException('Fix before publishing: '.$problems[0], 422, ['mapping' => $problems[0]]);
            }
            $previous = PdfTemplateVersion::query()->where('pdf_template_id', $version->pdf_template_id)->where('status', 'published')->first();
            if ($previous) {
                $previous->forceFill(['status' => 'superseded'])->save();
            }
            $version->forceFill(['status' => 'published', 'published_by' => $by->id, 'published_at' => now()])->save();
            $this->audit->record('official_template.version_published', $version, ['version' => $version->version, 'previous' => $previous?->version, 'fields' => count($version->mapping)], actor: $by);

            return $version;
        });
    }

    public function setActive(PdfTemplate $template, bool $active, User $by): void
    {
        $template->forceFill(['is_active' => $active])->save();
        $this->audit->record($active ? 'official_template.activated' : 'official_template.deactivated', $template, ['key' => $template->key], actor: $by);
    }

    public function saveOutput(PdfTemplate $template, array $data, User $by): WorkflowDocumentOutput
    {
        if (! empty($data['condition']) && ($p = $this->rules->problems($data['condition']))) {
            throw new WorkflowException('Condition: '.$p[0], 422, ['condition' => $p[0]]);
        }
        $output = WorkflowDocumentOutput::query()->where(['workflow_definition_id' => $data['workflow_definition_id'], 'pdf_template_id' => $template->id])->first()
            ?? (new WorkflowDocumentOutput)->forceFill(['workflow_definition_id' => (int) $data['workflow_definition_id'], 'pdf_template_id' => $template->id]);
        $output->forceFill([
            'is_enabled' => (bool) ($data['is_enabled'] ?? true), 'required_steps' => array_values($data['required_steps'] ?? []), 'condition' => $data['condition'] ?? null,
            'download_groups' => array_values(array_intersect($data['download_groups'] ?? ['applicant'], array_keys(WorkflowDocumentOutput::GROUPS))),
            'confidential' => (bool) ($data['confidential'] ?? false), 'requires_signature' => (bool) ($data['requires_signature'] ?? false),
            'filename_pattern' => $data['filename_pattern'] ?? '{template}_{reference}_Approved', 'regeneration' => $data['regeneration'] ?? 'authorised', 'updated_by' => $by->id,
        ]);
        $changes = $output->exists ? array_keys($output->getDirty()) : ['created'];
        $output->save();
        $this->audit->record('official_template.output_saved', $output, ['template' => $template->key, 'workflow' => $output->workflow_definition_id, 'changed' => $changes, 'groups' => $output->download_groups, 'confidential' => $output->confidential], actor: $by);

        return $output;
    }

    public function removeOutput(WorkflowDocumentOutput $output, User $by): void
    {
        // Documents already generated keep their link; this only stops new ones.
        $output->forceFill(['is_enabled' => false, 'updated_by' => $by->id])->save();
        $this->audit->record('official_template.output_disabled', $output, ['template' => $output->pdf_template_id, 'workflow' => $output->workflow_definition_id], actor: $by);
    }
}
