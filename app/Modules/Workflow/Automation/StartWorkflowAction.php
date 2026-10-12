<?php

namespace App\Modules\Workflow\Automation;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Opens a request on another workflow (e.g. a completed no-dues opens an
 * account-deprovisioning review). The new request goes through its own
 * approvals; this action decides nothing by itself. Idempotent per job.
 */
class StartWorkflowAction implements AutomationAction
{
    public function __construct(private WorkflowEngine $engine) {}

    public function label(): string
    {
        return 'Start another workflow';
    }

    public function settings(): array
    {
        return [
            'workflow' => ['label' => 'Workflow key to start', 'required' => true],
            'copy' => ['label' => 'Fields to copy (target => source field or {{ template }})', 'type' => 'map'],
        ];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $existing = WorkflowInstance::query()->where('variables->started_by_job', $job->uuid)->first();
        if ($existing) {
            return AutomationResult::success(['started_reference' => $existing->reference]);
        }
        $definition = WorkflowDefinition::query()->where('key', $config['workflow'] ?? '')->first();
        if (! $definition) {
            return AutomationResult::failure("Workflow '".($config['workflow'] ?? '')."' does not exist.");
        }

        $data = [];
        foreach ((array) ($config['copy'] ?? []) as $target => $source) {
            $data[$target] = match (true) {
                $source === 'initiator' => $instance->initiator_id,
                $source === 'reference' => $instance->reference,
                default => data_get($instance->data, (string) $source, $source),
            };
        }

        $started = $this->engine->start($definition, User::query()->findOrFail($instance->initiator_id), $data, submit: true, system: true, variables: ['started_by_job' => $job->uuid, 'parent' => $instance->reference]);

        return AutomationResult::success(['started_reference' => $started->reference], "Opened {$started->reference}.");
    }
}
