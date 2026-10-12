<?php

namespace App\Modules\ComputerCentre\Integrations;

class NotConfiguredVm implements VirtualMachineProvisioner
{
    public function provision(array $spec): ProvisionResult
    {
        return ProvisionResult::notConfigured('Virtual machine provisioning');
    }

    public function decommission(string $vmId): ProvisionResult
    {
        return ProvisionResult::notConfigured('Virtual machine decommissioning');
    }
}
