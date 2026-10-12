<?php

namespace App\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\DelegationService;
use App\Modules\Workflow\Http\InstancePresenter;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;

/** Figures on the personal dashboard. Every number is a query; nothing is hard-coded. */
class DashboardStats
{
    public function __construct(private DelegationService $delegations, private InstancePresenter $presenter) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $mine = fn () => WorkflowInstance::query()->where('initiator_id', $user->id);
        $tasks = fn () => WorkflowTask::query()->where('status', 'pending')->whereHas('instance', fn ($q) => $q->where('status', 'in_progress'));
        $principals = $this->delegations->principalsOf($user);

        return [
            'counts' => [
                'open' => $mine()->whereIn('status', ['in_progress', 'on_hold'])->count(),
                'returned' => $mine()->where('status', 'returned')->count(),
                'drafts' => $mine()->where('status', 'draft')->count(),
                'pending' => $tasks()->where('assignee_id', $user->id)->count(),
                'overdue' => $tasks()->where('assignee_id', $user->id)->where('due_at', '<', now())->count(),
                'delegated' => $principals ? $tasks()->whereIn('assignee_id', $principals)->count() : 0,
                'completed_30d' => $mine()->where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(),
            ],
            'recent' => $mine()->where('status', '!=', 'draft')->with(['definition:id,name', 'tasks' => fn ($q) => $q->where('status', 'pending')->with('assignee:id,name')])
                ->latest('updated_at')->limit(6)->get()->map(fn ($i) => $this->presenter->summary($i)),
            'due' => $tasks()->where('assignee_id', $user->id)->with('instance:id,reference,title')->orderByRaw('due_at asc nulls last')->limit(6)->get()
                ->map(fn (WorkflowTask $t) => [
                    'id' => $t->id, 'name' => $t->name, 'due_at' => $t->due_at?->toIso8601String(), 'overdue' => $t->isOverdue(),
                    'instance' => ['id' => $t->instance->id, 'reference' => $t->instance->reference, 'title' => $t->instance->title],
                ]),
        ];
    }
}
