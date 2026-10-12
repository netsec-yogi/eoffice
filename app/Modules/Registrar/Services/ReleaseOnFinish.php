<?php

namespace App\Modules\Registrar\Services;

use App\Modules\Registrar\Models\Circular;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\ReimbursementClaim;
use App\Modules\Registrar\Models\TravelAuthorisation;
use App\Modules\Workflow\Engine\WorkflowFinished;
use App\Modules\Workflow\Models\WorkflowInstance;

/** When a request is rejected or cancelled, registers release what it held. */
class ReleaseOnFinish
{
    public function handle(WorkflowFinished $e): void
    {
        if (! in_array($e->status, ['rejected', 'cancelled'], true)) {
            return;
        }
        LeaveRecord::query()->where('workflow_instance_id', $e->instanceId)->where('status', 'pending')->update(['status' => $e->status, 'updated_at' => now()]);
        TravelAuthorisation::query()->where('workflow_instance_id', $e->instanceId)->where('status', 'pending')->update(['status' => $e->status, 'updated_at' => now()]);
        $claim = ReimbursementClaim::query()->where('workflow_instance_id', $e->instanceId)->whereIn('status', ['pending', 'sanctioned'])->first();
        if ($claim) {
            $claim->forceFill(['status' => $e->status])->save();
            if ($claim->travel_authorisation_id) {
                TravelAuthorisation::query()->whereKey($claim->travel_authorisation_id)->update(['claim_status' => 'none']);
            }
        }
        $instance = WorkflowInstance::query()->find($e->instanceId);
        if ($instance?->subject_type === 'circular') {
            Circular::query()->whereKey($instance->subject_id)->where('status', 'in_review')->update(['status' => 'draft', 'updated_at' => now()]);
        }
    }
}
