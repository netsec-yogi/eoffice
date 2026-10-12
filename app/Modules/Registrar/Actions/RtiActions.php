<?php

namespace App\Modules\Registrar\Actions;

use App\Modules\Registrar\Models\RtiRequest;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Support\SequenceGenerator;

/** RTI register steps: register | reply | appeal_filed | appeal_decided. Idempotent. */
class RtiActions implements AutomationAction
{
    public function __construct(private SequenceGenerator $sequences) {}

    public function label(): string
    {
        return 'RTI: update the register';
    }

    public function settings(): array
    {
        return ['step' => ['label' => 'Step (register, reply, appeal_filed, appeal_decided)', 'required' => true]];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $d = $instance->data;
        switch ($config['step'] ?? '') {
            case 'register':
                $rti = (RtiRequest::query()->where('workflow_instance_id', $instance->id)->first() ?? (new RtiRequest)->forceFill(['workflow_instance_id' => $instance->id]));
                if (! $rti->exists) {
                    $rti->registration_number = $this->sequences->format('RTI', 'RTI/{YYYY}/{SEQ:4}');
                }
                $rti->forceFill([
                    'applicant_name' => $d['applicant_name'], 'applicant_address' => $d['applicant_address'] ?? null, 'applicant_email' => $d['applicant_email'] ?? null,
                    'applicant_phone' => $d['applicant_phone'] ?? null, 'is_bpl' => (bool) ($d['is_bpl'] ?? false), 'fee_paid' => (bool) ($d['fee_paid'] ?? false),
                    'received_on' => $d['received_on'], 'received_via' => $d['received_via'], 'life_liberty' => (bool) ($d['life_liberty'] ?? false),
                    'subject' => $d['subject'], 'pio_id' => $d['pio'] ?? null, 'due_on' => $d['due_on'],
                ])->save();

                return AutomationResult::success(['rti_number' => $rti->registration_number]);

            case 'reply':
                $rti = RtiRequest::query()->where('workflow_instance_id', $instance->id)->firstOrFail();
                $transferred = ($d['outcome'] ?? '') === 'transferred';
                $rti->forceFill([
                    'status' => $transferred ? 'transferred' : 'replied', 'outcome' => $d['outcome'] ?? null,
                    'transferred_to' => $transferred ? ($d['transferred_to'] ?? null) : null, 'transferred_on' => $transferred ? ($d['dispatched_on'] ?? today()->toDateString()) : null,
                    'replied_on' => $d['dispatched_on'] ?? today()->toDateString(),
                ])->save();

                return AutomationResult::success(['late' => $rti->replied_on->gt($rti->due_on)]);

            case 'appeal_filed':
                RtiRequest::query()->whereKey($d['rti'])->update(['appeal_status' => 'filed', 'appeal_received_on' => $d['appeal_received_on'], 'appeal_due_on' => $d['appeal_due_on'], 'appeal_instance_id' => $instance->id, 'updated_at' => now()]);

                return AutomationResult::success();

            case 'appeal_decided':
                RtiRequest::query()->whereKey($d['rti'])->update(['appeal_status' => 'decided', 'appeal_decided_on' => today()->toDateString(), 'updated_at' => now()]);

                return AutomationResult::success();
        }

        return AutomationResult::failure('Unknown RTI step.');
    }
}
