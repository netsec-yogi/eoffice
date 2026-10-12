<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\ComputerCentre\Models\ItAsset;
use App\Modules\ComputerCentre\Models\LicenceAllocation;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * No-dues clearance for the Computer Centre: succeeds when the person holds
 * no IT assets; otherwise fails with the list, so the workflow can send the
 * case to the Computer Centre. Active accounts are reported, not blocking:
 * they are dealt with by the deprovisioning review.
 */
class CheckClearance implements AutomationAction
{
    use ResolvesAccountHolder;

    public function label(): string
    {
        return 'Computer Centre: no-dues clearance check';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $user = $this->holder($instance);
        $assets = ItAsset::query()->where('custodian_id', $user->id)->where('status', 'issued')->pluck('asset_number')->all();
        $accounts = ItAccount::query()->where('user_id', $user->id)->where('status', 'active')->pluck('kind')->all();
        $licences = LicenceAllocation::query()->where('user_id', $user->id)->whereNull('revoked_at')->count();

        $output = ['cc_assets_outstanding' => implode(', ', $assets), 'cc_active_accounts' => implode(', ', $accounts), 'cc_licences' => $licences];

        return $assets === []
            ? AutomationResult::success($output, 'No IT assets outstanding.')
            : AutomationResult::failure('Assets still issued: '.implode(', ', $assets).'.', $output);
    }
}
