<?php

namespace App\Modules\Workflow\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Engine\DelegationService;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;

/**
 * Who may see a request (record-level authorization, SEC/§17.1): its
 * initiator, everyone who has or had a task on it, their current delegates,
 * institute-wide monitors, and department monitors for their own units.
 */
class WorkflowInstancePolicy
{
    public function __construct(private DelegationService $delegations) {}

    public function view(User $user, WorkflowInstance $instance): bool
    {
        // External parties never see internal deliberations (Brief §14.3);
        // vendors follow their bills in the vendor portal.
        if (in_array($user->category, ['vendor', 'external'], true)) {
            return false;
        }
        if ($instance->initiator_id === $user->id || $user->can(Permissions::WORKFLOWS_MONITOR)) {
            return true;
        }
        if ($instance->status === 'draft') {
            return false;
        }
        $involved = WorkflowTask::query()->where('workflow_instance_id', $instance->id)
            ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere('principal_id', $user->id))->exists();
        if ($involved) {
            return true;
        }
        $principals = $this->delegations->principalsOf($user);
        if ($principals && WorkflowTask::query()->where('workflow_instance_id', $instance->id)->where('status', 'pending')->whereIn('assignee_id', $principals)->exists()) {
            return true;
        }

        return $user->can(Permissions::WORKFLOWS_MONITOR_DEPARTMENT)
            && $user->department
            && in_array($instance->department_id, $user->department->descendantIds(), true);
    }

    public function edit(User $user, WorkflowInstance $instance): bool
    {
        return $instance->initiator_id === $user->id && in_array($instance->status, ['draft', 'returned'], true);
    }

    public function intervene(User $user, WorkflowInstance $instance): bool
    {
        return $user->can(Permissions::WORKFLOWS_INTERVENE) && $instance->isOpen();
    }
}
