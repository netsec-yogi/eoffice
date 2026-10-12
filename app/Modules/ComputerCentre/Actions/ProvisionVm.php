<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Integrations\VirtualMachineProvisioner;
use App\Modules\ComputerCentre\Services\AccountLedger;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

class ProvisionVm implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(private VirtualMachineProvisioner $vms, private AccountLedger $ledger) {}

    public function label(): string
    {
        return 'Computer Centre: create virtual machine';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $user = $this->holder($instance);
        $d = $instance->data;
        $result = $this->vms->provision([
            'name' => "{$instance->reference} {$user->username}",
            'os' => (string) ($d['os'] ?? ''),
            'cpu' => (int) ($d['cpu'] ?? 1),
            'ram_gb' => (int) ($d['ram_gb'] ?? 1),
            'disk_gb' => (int) ($d['disk_gb'] ?? 10),
            'idempotency_key' => $job->idempotency_key,
        ]);
        if ($result->isOk()) {
            $this->ledger->record($user, 'vm', $result->identifier, $result->details + ['cpu' => $d['cpu'] ?? null, 'ram_gb' => $d['ram_gb'] ?? null, 'disk_gb' => $d['disk_gb'] ?? null], $instance->id, 'automation', $d['valid_until'] ?? null);
        }

        return $this->toResult($result, ['vm_id' => $result->identifier]);
    }
}
