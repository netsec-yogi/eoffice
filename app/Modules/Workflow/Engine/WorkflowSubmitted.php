<?php

namespace App\Modules\Workflow\Engine;

/** A request was submitted (dispatched after commit). Modules prepare what they will need at its end. */
final readonly class WorkflowSubmitted
{
    public function __construct(public int $instanceId) {}
}
