<?php

namespace App\Modules\Registrar\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\TemplateRenderer;
use App\Modules\Registrar\Models\Certificate;
use App\Modules\Registrar\Models\CertificateTemplate;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Support\SequenceGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Certificates and NOCs (REG-002): numbered from the template's pattern,
 * rendered to PDF, attached to the request, verifiable by code at
 * /verify/{code}. The PDF names the approving officer as signatory; it is
 * NOT a digital signature (Phase 8 adds eSign/DSC).
 */
class CertificateService
{
    public function __construct(private SequenceGenerator $sequences, private TemplateRenderer $renderer, private DocumentStore $documents, private AuditLogger $audit) {}

    public function issue(CertificateTemplate $template, User $holder, User $signatory, ?WorkflowInstance $instance = null, array $data = []): Certificate
    {
        if ($instance && ($existing = Certificate::query()->where(['workflow_instance_id' => $instance->id, 'certificate_template_id' => $template->id])->first())) {
            return $existing; // replayed step
        }

        return DB::transaction(function () use ($template, $holder, $signatory, $instance, $data) {
            $holder->loadMissing('designation', 'department');
            $signatory->loadMissing('designation');
            $number = $this->sequences->format("CERT:{$template->code}", $template->numbering_pattern);
            $vars = [
                'holder' => ['name' => $holder->name, 'designation' => $holder->designation?->name ?? '', 'department' => $holder->department?->name ?? '', 'employee_id' => $holder->employee_id ?? ''],
                'data' => $data, ...$data,
                'number' => $number,
                'date' => now()->format('d F Y'),
            ];
            $code = Str::upper(Str::random(12));

            $certificate = new Certificate;
            $certificate->forceFill([
                'number' => $number, 'certificate_template_id' => $template->id, 'user_id' => $holder->id, 'workflow_instance_id' => $instance?->id,
                'body' => (string) $this->renderer->render($template->body, $vars),
                'signatory_name' => $signatory->name, 'signatory_designation' => $signatory->designation?->name,
                'issued_at' => now(), 'verification_code' => $code,
            ])->save();

            $pdf = Pdf::loadView('pdf.certificate', [
                'certificate' => $certificate, 'title' => $template->title, 'footer' => $this->renderer->render($template->footer, $vars),
                'verifyUrl' => route('certificates.verify', $code), 'institute' => config('app.name'),
            ])->output();
            $path = tempnam(sys_get_temp_dir(), 'cert');
            file_put_contents($path, $pdf);
            $document = $this->documents->store(new UploadedFile($path, Str::slug($number).'.pdf', 'application/pdf', null, true), $instance, $signatory, 'certificate', 'internal');
            $certificate->forceFill(['document_id' => $document->id])->save();

            $this->audit->record('certificate.issued', $certificate, ['number' => $number, 'template' => $template->code, 'holder' => $holder->id], actor: $signatory);

            return $certificate;
        });
    }

    public function revoke(Certificate $certificate, User $by, string $reason): void
    {
        $certificate->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
        $this->audit->record('certificate.revoked', $certificate, ['reason' => $reason], actor: $by);
    }
}
