<?php

namespace App\Modules\OfficialDocuments\Services;

use App\Modules\Workflow\Engine\WorkflowFinished;
use App\Modules\Workflow\Engine\WorkflowSubmitted;
use App\Modules\Workflow\Models\WorkflowInstance;

/** Connects the engine's committed events to official documents. */
class WorkflowListener
{
    public function __construct(private OfficialDocumentService $documents) {}

    public function submitted(WorkflowSubmitted $e): void
    {
        if ($instance = WorkflowInstance::query()->find($e->instanceId)) {
            $this->documents->prepare($instance);
        }
    }

    public function finished(WorkflowFinished $e): void
    {
        if ($instance = WorkflowInstance::query()->find($e->instanceId)) {
            $this->documents->finished($instance);
        }
    }
}
