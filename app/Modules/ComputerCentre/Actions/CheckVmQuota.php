<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/** Fails (taking the workflow's failure path) when a VM request exceeds the requester's quota. */
class CheckVmQuota implements AutomationAction
{
    use ResolvesAccountHolder;

    public function label(): string
    {
        return 'Computer Centre: check VM quota';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $user = $this->holder($instance);
        $quota = config("computer_centre.vm.quotas.{$user->category}");
        if (! $quota) {
            return AutomationResult::failure("No VM quota is defined for the category '{$user->category}'.");
        }
        $over = [];
        foreach (['cpu' => 'vCPU', 'ram_gb' => 'GB RAM', 'disk_gb' => 'GB disk'] as $key => $unit) {
            if ((float) ($instance->data[$key] ?? 0) > $quota[$key]) {
                $over[] = "{$instance->data[$key]} {$unit} requested, limit {$quota[$key]}";
            }
        }

        return $over ? AutomationResult::failure('Above quota: '.implode('; ', $over).'.') : AutomationResult::success(['quota_ok' => true]);
    }
}
