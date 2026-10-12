<?php

namespace App\Modules\OfficialDocuments\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Permissions;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Pdf\GenerationException;
use App\Modules\OfficialDocuments\Services\OfficialDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generation monitor (§18): status, failures with their technical detail,
 * retry and authorised reissue. Seeing this page does not let anyone read
 * the documents themselves.
 */
class MonitorController extends Controller
{
    private const STATUSES = ['waiting_approval', 'queued', 'generating', 'generated', 'awaiting_signature', 'failed', 'not_generated', 'superseded'];

    public function index(Request $request): Response
    {
        $this->authorize(Permissions::OFFICIAL_DOCUMENTS_MANAGE);
        $f = $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'q' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Admin/OfficialDocuments/Index', [
            'filters' => $f,
            'counts' => OfficialDocument::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'documents' => OfficialDocument::query()->with(['instance:id,reference,status,workflow_definition_id', 'instance.definition:id,name', 'output.template:id,name', 'templateVersion:id,version'])
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($f['q'] ?? null, fn ($q, $t) => $q->whereHas('instance', fn ($i) => $i->where('reference', 'ilike', "%{$t}%")))
                ->latest('updated_at')->paginate(30)->withQueryString()
                ->through(fn (OfficialDocument $d) => [
                    ...$d->only('id', 'uuid', 'status', 'attempts', 'error_code', 'error_detail', 'document_version', 'sha256', 'regeneration_reason'),
                    'reason' => GenerationException::CODES[$d->error_code] ?? ($d->error_code === 'signature_provider_missing' ? 'No signature provider configured' : $d->error_code),
                    'template' => $d->output->template->name, 'template_version' => $d->templateVersion?->version,
                    'reference' => $d->instance->reference, 'instance_id' => $d->instance->id, 'instance_status' => $d->instance->status, 'workflow' => $d->instance->definition->name,
                    'regenerable' => $d->output->regeneration === 'authorised' && in_array($d->status, ['generated', 'awaiting_signature'], true),
                    'generated_at' => $d->generated_at?->toIso8601String(), 'updated_at' => $d->updated_at->toIso8601String(),
                ]),
        ]);
    }

    public function retry(Request $request, OfficialDocument $document, OfficialDocumentService $service): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_DOCUMENTS_MANAGE);
        $service->retry($document, $request->user());

        return back()->with('success', 'Queued again.');
    }

    public function regenerate(Request $request, OfficialDocument $document, OfficialDocumentService $service): RedirectResponse
    {
        $this->authorize(Permissions::OFFICIAL_DOCUMENTS_MANAGE);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000'], 'use_current_version' => ['boolean']]);
        $service->regenerate($document, $request->user(), $data['reason'], (bool) ($data['use_current_version'] ?? false));

        return back()->with('success', 'Reissue queued. The earlier document is kept as superseded.');
    }
}
