<?php

namespace App\Modules\ComputerCentre\Integrations;

/** Creates and removes virtual machines. */
interface VirtualMachineProvisioner
{
    /**
     * @param  array{name: string, os: string, cpu: int, ram_gb: int, disk_gb: int, idempotency_key: string}  $spec
     */
    public function provision(array $spec): ProvisionResult;

    public function decommission(string $vmId): ProvisionResult;
}
