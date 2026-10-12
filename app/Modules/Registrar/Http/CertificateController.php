<?php

namespace App\Modules\Registrar\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Permissions;
use App\Modules\Registrar\Models\Certificate;
use App\Modules\Registrar\Models\CertificateTemplate;
use App\Modules\Registrar\Services\CertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CertificateController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $manage = $user->can(Permissions::REGISTRAR_MANAGE);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return Inertia::render('Certificates/Index', [
            'manage' => $manage,
            'q' => $q,
            'certificates' => Certificate::query()->with(['template:id,name', 'user:id,name'])
                ->when(! $manage, fn ($w) => $w->where('user_id', $user->id))
                ->when($q, fn ($w) => $w->where(fn ($w) => $w->where('number', 'ilike', "%{$q}%")->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%"))))
                ->latest('issued_at')->limit(200)->get()
                ->map(fn ($c) => [
                    ...$c->only('id', 'number', 'verification_code', 'signatory_name', 'revoke_reason', 'workflow_instance_id'),
                    'template' => $c->template->name, 'holder' => $c->user->name, 'issued_at' => $c->issued_at->toIso8601String(), 'revoked' => $c->revoked_at !== null,
                    'pdf' => ($doc = Document::query()->find($c->document_id)) ? $doc->toSummary()['url'] : null,
                    'verify_url' => route('certificates.verify', $c->verification_code),
                ]),
            'templates' => $manage ? CertificateTemplate::query()->orderBy('name')->get() : [],
        ]);
    }

    public function storeTemplate(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $t = CertificateTemplate::create($this->templateData($request));
        $audit->record('certificate.template.created', $t, $t->only('code', 'numbering_pattern'));

        return back()->with('success', 'Template created.');
    }

    public function updateTemplate(Request $request, CertificateTemplate $template, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $template->fill($this->templateData($request, $template));
        $changes = array_keys($template->getDirty());
        $template->save();
        $audit->record('certificate.template.updated', $template, ['fields' => $changes]);

        return back()->with('success', 'Template saved. Certificates already issued keep their text.');
    }

    public function revoke(Request $request, Certificate $certificate, CertificateService $service): RedirectResponse
    {
        $this->authorize(Permissions::REGISTRAR_MANAGE);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $service->revoke($certificate, $request->user(), $data['reason']);

        return back()->with('success', 'Certificate revoked. Verification now shows it as revoked.');
    }

    /**
     * Public verification (no sign-in). Shows only what a third party needs
     * to confirm the document: number, kind, holder's name, date, validity.
     */
    public function verify(string $code): Response
    {
        $c = Certificate::query()->with('template:id,name', 'user:id,name')->where('verification_code', strtoupper($code))->first();

        return Inertia::render('Certificates/Verify', [
            'certificate' => $c ? [
                'number' => $c->number, 'kind' => $c->template->name, 'holder' => $c->user->name,
                'issued_on' => $c->issued_at->toDateString(), 'signatory' => $c->signatory_name, 'valid' => $c->revoked_at === null,
            ] : null,
        ]);
    }

    private function templateData(Request $request, ?CertificateTemplate $t = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('certificate_templates')->ignore($t)],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'numbering_pattern' => ['required', 'string', 'max:100', 'regex:/\{SEQ(:\d+)?\}/'],
            'footer' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
