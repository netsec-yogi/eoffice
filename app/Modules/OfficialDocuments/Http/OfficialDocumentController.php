<?php

namespace App\Modules\OfficialDocuments\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Services\OfficialDocumentAccess;
use App\Modules\OfficialDocuments\Services\OfficialDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Official documents": approved PDFs for the people entitled to them (DOC-004). */
class OfficialDocumentController extends Controller
{
    public function __construct(private OfficialDocumentAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $rows = $this->access->listable($user)->with(['instance.definition:id,name', 'output.template:id,name', 'templateVersion:id,version'])
            ->latest('id')->limit(300)->get();

        return Inertia::render('OfficialDocuments/Index', [
            'documents' => $rows->map(fn (OfficialDocument $d) => self::row($d, $this->access->canDownload($user, $d), $user->id === $d->instance->initiator_id))->values(),
        ]);
    }

    /**
     * The only way an official document leaves the system: released, the
     * requester entitled, and the download recorded. A guessed id, a
     * reference number or the storage path gives nothing.
     */
    public function download(Request $request, OfficialDocument $document, AuditLogger $audit, OfficialDocumentService $service): StreamedResponse
    {
        $user = $request->user();
        if (! $this->access->canDownload($user, $document)) {
            $audit->record('official_document.download_denied', $document, ['status' => $document->status], outcome: 'denied', actor: $user);
            abort(404); // the same answer whether it does not exist, is not ready or is not yours
        }
        $file = $document->file()->firstOrFail();
        $audit->record('official_document.downloaded', $document, ['reference' => $document->instance->reference, 'document_version' => $document->document_version, 'sha256' => $document->sha256], actor: $user);

        return Storage::disk($file->disk)->download($file->path, $document->filename ?? $service->downloadName($document), [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    /** What a person sees about a document: status in plain words, never internals. */
    public static function row(OfficialDocument $d, bool $canDownload, bool $mine): array
    {
        $instance = $d->instance;
        $label = match (true) {
            $d->status === 'waiting_approval' && $instance->status === 'returned' => 'Returned for clarification',
            $d->status === 'waiting_approval' && $instance->status === 'on_hold' => 'Request on hold',
            $d->status === 'waiting_approval' => 'Waiting for final approval',
            in_array($d->status, ['queued', 'generating'], true) => 'Being prepared',
            $d->status === 'generated' => 'Ready',
            $d->status === 'awaiting_signature' => 'Awaiting authorised signature',
            $d->status === 'failed' => 'Delayed: the office has been informed',
            $d->status === 'not_generated' => $d->error_code === 'condition_not_met' ? 'Not required for this request' : 'Not issued (request not approved)',
            $d->status === 'superseded' => 'Replaced by a reissued version',
            default => ucfirst(str_replace('_', ' ', $d->status)),
        };

        return [
            'uuid' => $d->uuid, 'name' => $d->output->template->name, 'status' => $d->status, 'status_label' => $label,
            'reference' => $instance->reference, 'workflow' => $instance->definition->name, 'instance_id' => $instance->id, 'instance_status' => $instance->status,
            'submitted_at' => $instance->submitted_at?->toIso8601String(), 'approved_at' => $d->approved_at?->toIso8601String(), 'generated_at' => $d->generated_at?->toIso8601String(),
            'template_version' => $d->templateVersion?->version, 'document_version' => $d->document_version, 'mine' => $mine,
            'download' => $canDownload ? route('official-documents.download', $d) : null,
        ];
    }
}
