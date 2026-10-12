<?php

namespace App\Modules\EFile\Services;

use App\Modules\EFile\Models\EFile;
use App\Modules\Workflow\Engine\WorkflowFinished;
use App\Modules\Workflow\Models\WorkflowInstance;

/** Writes the outcome of a workflow a file was routed through into the file's history. */
class RecordWorkflowOutcome
{
    public function __construct(private EFileService $files) {}

    public function handle(WorkflowFinished $event): void
    {
        $instance = WorkflowInstance::query()->with('actions.actor:id,name')->find($event->instanceId);
        if (! $instance || $instance->subject_type !== 'efile' || ! ($file = EFile::query()->find($instance->subject_id))) {
            return;
        }
        $last = $instance->actions->where('is_decision', true)->last();
        $label = ['approved' => 'approved', 'rejected' => 'not approved', 'cancelled' => 'cancelled'][$event->outcome] ?? $event->outcome;
        $this->files->event($file, 'workflow_completed', $last?->actor, "Request {$instance->reference} {$label}".($last?->actor ? " ({$last->actor->name})" : ''), [
            'instance' => $instance->id, 'outcome' => $event->outcome, 'remarks' => $last?->remarks,
        ]);
    }
}
