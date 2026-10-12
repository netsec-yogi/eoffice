<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\GoodsReceipt;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Workflow\Engine\WorkflowFinished;

/** Rejected / cancelled requests release what they held in the finance registers. */
class ReleaseOnFinish
{
    public function __construct(private ProcurementService $procurement) {}

    public function handle(WorkflowFinished $e): void
    {
        if (! in_array($e->status, ['rejected', 'cancelled'], true)) {
            return;
        }
        $case = ProcurementCase::query()->where('workflow_instance_id', $e->instanceId)->whereNotIn('status', ['closed', 'rejected', 'cancelled'])->first();
        if ($case && ! $case->orders()->where('status', '!=', 'cancelled')->exists()) {
            $this->procurement->close($case, $e->status); // gives the commitment back to the budget head
        }
        VendorInvoice::query()->where('workflow_instance_id', $e->instanceId)->where('status', '!=', 'paid')->update(['status' => 'rejected', 'updated_at' => now()]);
        GoodsReceipt::query()->where('workflow_instance_id', $e->instanceId)->where('status', 'pending')->update(['status' => 'rejected', 'updated_at' => now()]);
        Advance::query()->where('workflow_instance_id', $e->instanceId)->whereIn('status', ['requested', 'sanctioned'])->update(['status' => $e->status, 'updated_at' => now()]);
    }
}
