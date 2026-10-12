<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\ComputerCentre\Services\AccountLedger;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Makes sure the ledger knows about access granted by hand (after a manual
 * step), so that deprovisioning later finds it. A no-op when automation
 * already recorded it.
 */
class RecordAccount implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(private AccountLedger $ledger) {}

    public function label(): string
    {
        return 'Computer Centre: record granted access in the ledger';
    }

    public function settings(): array
    {
        return ['kind' => ['label' => 'Kind (email, wifi, vpn, vm)', 'required' => true]];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $kind = in_array($config['kind'] ?? '', ItAccount::KINDS, true) ? $config['kind'] : throw new \InvalidArgumentException('Unknown account kind.');
        $user = $this->holder($instance);
        $vars = $instance->variables ?? [];
        $identifier = match ($kind) {
            'email' => $vars['email_address'] ?? $instance->data['preferred_address'] ?? null,
            'wifi', 'vpn' => $vars["{$kind}_group"] ?? config("computer_centre.network.groups.{$kind}"),
            'vm' => $vars['vm_id'] ?? $instance->data['vm_id'] ?? null,
        } ?? "manual:{$instance->reference}";

        $already = ItAccount::query()->where(['user_id' => $user->id, 'kind' => $kind, 'workflow_instance_id' => $instance->id])->exists();
        if (! $already) {
            $this->ledger->record($user, $kind, (string) $identifier, ['recorded_after' => 'manual completion'], $instance->id, 'manual', $instance->data['valid_until'] ?? null);
        }

        return AutomationResult::success();
    }
}
