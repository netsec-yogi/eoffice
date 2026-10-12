<?php

namespace App\Modules\Registrar\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\CertificateTemplate;
use App\Modules\Registrar\Services\CertificateService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/** Issues the certificate named in the step settings; the last approver signs. */
class IssueCertificate implements AutomationAction
{
    public function __construct(private CertificateService $certificates) {}

    public function label(): string
    {
        return 'Registrar: issue certificate / NOC';
    }

    public function settings(): array
    {
        return ['template' => ['label' => 'Certificate template code', 'required' => true]];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $template = CertificateTemplate::query()->where('code', $config['template'] ?? '')->where('is_active', true)->first();
        if (! $template) {
            return AutomationResult::failure("Certificate template '".($config['template'] ?? '')."' does not exist or is inactive.");
        }
        $signatoryId = $instance->actions()->where('is_decision', true)->where('action', 'approved')->reorder()->orderByDesc('id')->value('actor_id');
        if (! $signatoryId) {
            return AutomationResult::failure('No approving officer to sign the certificate.');
        }
        $certificate = $this->certificates->issue($template, User::query()->findOrFail($instance->initiator_id), User::query()->findOrFail($signatoryId), $instance, $instance->data ?? []);

        return AutomationResult::success(['certificate_number' => $certificate->number], "Issued {$certificate->number}.");
    }
}
