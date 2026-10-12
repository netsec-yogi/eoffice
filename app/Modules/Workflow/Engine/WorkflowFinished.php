<?php

namespace App\Modules\Workflow\Engine;

/** Dispatched after the transaction that finished a request commits. */
final readonly class WorkflowFinished
{
    public function __construct(public int $instanceId, public string $status, public string $outcome) {}
}
