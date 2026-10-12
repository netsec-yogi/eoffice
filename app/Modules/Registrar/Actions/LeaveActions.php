<?php

namespace App\Modules\Registrar\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Services\LeaveService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * leave.reserve  -> hold the days while the application is decided
 * leave.approve  -> debit the ledger (always recomputed from the final data)
 * leave.cancel   -> credit back days of a cancelled or curtailed leave
 * Rejected / cancelled applications release their hold (ReleaseOnFinish).
 */
class LeaveActions implements AutomationAction
{
    public function __construct(private LeaveService $leave, private string $mode = 'reserve') {}

    public function label(): string
    {
        return ['reserve' => 'Leave: hold days while pending', 'approve' => 'Leave: record approval and debit balance', 'cancel' => 'Leave: cancel / curtail and credit back'][$this->mode];
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $user = User::query()->findOrFail($instance->initiator_id);
        $d = $instance->data;

        if ($this->mode === 'cancel') {
            $record = LeaveRecord::query()->with('type')->where('user_id', $user->id)->findOrFail($d['leave']);
            $restored = (float) ($d['days_restored'] ?? 0);
            if (in_array($record->status, ['approved', 'curtailed'], true)) {
                $newTo = $d['new_to_date'] ?? null;
                $record->forceFill($newTo
                    ? ['to_date' => $newTo, 'days' => $record->days - $restored, 'status' => 'curtailed']
                    : ['status' => 'cancelled'])->save();
            }
            if ($record->type->has_balance && $restored > 0) {
                $this->leave->post($user, $record->type, 'reversal', $restored, 'Leave '.($d['new_to_date'] ?? null ? 'curtailed' : 'cancelled')." ({$instance->reference})", null, $record, $instance->id);
            }

            return AutomationResult::success(['days_restored' => $restored]);
        }

        $type = LeaveType::query()->where('code', $d['leave_type'])->firstOrFail();
        $record = (LeaveRecord::query()->where('workflow_instance_id', $instance->id)->first() ?? (new LeaveRecord)->forceFill(['workflow_instance_id' => $instance->id]));
        $record->forceFill([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'from_date' => $d['from_date'], 'to_date' => $d['to_date'],
            'half_day' => (bool) ($d['half_day'] ?? false), 'days' => (float) $d['days'],
        ]);
        if ($this->mode === 'reserve') {
            if ($record->exists && $record->status !== 'pending') {
                return AutomationResult::success(); // already decided; a replay changes nothing
            }
            $record->status = 'pending';
            $record->save();

            return AutomationResult::success();
        }

        // approve
        $record->forceFill(['status' => 'approved', 'approved_at' => $record->approved_at ?? now()])->save();
        if ($type->has_balance) {
            $this->leave->post($user, $type, 'debit', -1 * (float) $d['days'], "{$type->name} {$d['from_date']} – {$d['to_date']} ({$instance->reference})", null, $record, $instance->id);
        }

        return AutomationResult::success(['leave_record' => $record->id]);
    }
}
