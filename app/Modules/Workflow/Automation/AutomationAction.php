<?php

namespace App\Modules\Workflow\Automation;

use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Work a workflow step performs without a person (WF-016): provisioning an
 * account, calling another system. Registered in config/workflow.php under
 * a stable key; the designer offers only registered actions.
 *
 * Implementations must be idempotent: a retried job carries the same
 * IntegrationJob (and idempotency key) and must not repeat an irreversible
 * effect.
 */
interface AutomationAction
{
    public function label(): string;

    /** @return array<string, array{label: string, type?: string, required?: bool}> settings shown in the designer */
    public function settings(): array;

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult;
}
