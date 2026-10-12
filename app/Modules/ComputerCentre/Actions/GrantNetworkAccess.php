<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Integrations\NetworkAccessProvisioner;
use App\Modules\ComputerCentre\Services\AccountLedger;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

class GrantNetworkAccess implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(private NetworkAccessProvisioner $network, private AccountLedger $ledger) {}

    public function label(): string
    {
        return 'Computer Centre: grant Wi-Fi / VPN access';
    }

    public function settings(): array
    {
        return ['profile' => ['label' => 'Profile (wifi or vpn)', 'required' => true]];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $profile = in_array($config['profile'] ?? '', ['wifi', 'vpn'], true) ? $config['profile'] : throw new \InvalidArgumentException('profile must be wifi or vpn');
        $user = $this->holder($instance);
        $result = $this->network->grant($user, $profile);

        if ($result->isOk()) {
            $this->ledger->record($user, $profile, $result->identifier, ['scope' => $instance->data['resources'] ?? null], $instance->id, 'automation', $instance->data['valid_until'] ?? null);
        }

        return $this->toResult($result, ["{$profile}_group" => $result->identifier]);
    }
}
