<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Models\ItAsset;
use App\Modules\ComputerCentre\Services\AssetService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;

/** Issues the asset chosen at the store step (field asset_number) to the requester. */
class IssueAsset implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(private AssetService $assets) {}

    public function label(): string
    {
        return 'Computer Centre: issue the selected asset';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $number = trim((string) ($instance->data['asset_number'] ?? ''));
        $asset = ItAsset::query()->where('asset_number', $number)->first();
        if (! $asset) {
            return AutomationResult::failure($number === '' ? 'No asset was selected at the store step.' : "Asset {$number} is not in the register.");
        }
        try {
            $this->assets->move($asset, 'issue', null, $this->holder($instance), "Issued against {$instance->reference}", $instance->id);
        } catch (ValidationException $e) {
            return AutomationResult::failure(collect($e->errors())->flatten()->first());
        }

        return AutomationResult::success(['issued_asset' => $number]);
    }
}
