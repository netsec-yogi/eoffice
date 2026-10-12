<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\DelegationService;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The approver's inbox and decisions (WF-010, WF-017). */
class TaskController extends Controller
{
    public function __construct(private WorkflowEngine $engine, private DelegationService $delegations) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['pending', 'delegated', 'overdue', 'done'])]])['tab'] ?? 'pending';
        $principals = $this->delegations->principalsOf($user);

        $base = WorkflowTask::query()->with(['instance.definition:id,name', 'instance.initiator:id,name', 'principal:id,name', 'assignee:id,name']);
        $query = match ($tab) {
            'pending' => $base->where('assignee_id', $user->id)->where('status', 'pending')->whereHas('instance', fn ($q) => $q->whereIn('status', ['in_progress', 'on_hold'])),
            'delegated' => $base->whereIn('assignee_id', $principals ?: [0])->where('status', 'pending')->whereHas('instance', fn ($q) => $q->where('status', 'in_progress')),
            'overdue' => $base->where('assignee_id', $user->id)->where('status', 'pending')->where('due_at', '<', now())->whereHas('instance', fn ($q) => $q->where('status', 'in_progress')),
            'done' => $base->where('completed_by', $user->id)->where('status', 'completed'),
        };
        $tasks = $query->orderBy($tab === 'done' ? 'completed_at' : 'due_at', $tab === 'done' ? 'desc' : 'asc')->orderBy('id')->paginate(25)->withQueryString()
            ->through(fn (WorkflowTask $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'kind' => $t->kind,
                'status' => $t->status,
                'outcome' => $t->outcome,
                'due_at' => $t->due_at?->toIso8601String(),
                'overdue' => $t->isOverdue(),
                'escalation_level' => $t->escalation_level,
                'on_behalf_of' => $t->principal?->name,
                'assignee' => $t->assignee?->name,
                'created_at' => $t->created_at->toIso8601String(),
                'completed_at' => $t->completed_at?->toIso8601String(),
                'instance' => [
                    'id' => $t->instance->id,
                    'reference' => $t->instance->reference,
                    'title' => $t->instance->title,
                    'status' => $t->instance->status,
                    'workflow' => $t->instance->definition->name,
                    'initiator' => $t->instance->initiator->name,
                ],
            ]);

        $pending = fn () => WorkflowTask::query()->where('status', 'pending')->whereHas('instance', fn ($q) => $q->where('status', 'in_progress'));

        return Inertia::render('Tasks/Index', [
            'tab' => $tab,
            'tasks' => $tasks,
            'counts' => [
                'pending' => $pending()->where('assignee_id', $user->id)->count(),
                'delegated' => $principals ? $pending()->whereIn('assignee_id', $principals)->count() : 0,
                'overdue' => $pending()->where('assignee_id', $user->id)->where('due_at', '<', now())->count(),
            ],
        ]);
    }

    public function show(WorkflowTask $task): RedirectResponse
    {
        return redirect()->route('requests.show', ['instance' => $task->workflow_instance_id, 'task' => $task->id]);
    }

    public function decide(Request $request, WorkflowTask $task): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'return', 'abstain', 'complete', 'fail'])],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'return_kind' => ['nullable', Rule::in(['clarification', 'documents'])],
            'data' => ['nullable', 'array'],
        ]);
        $this->engine->decide($task, $request->user(), $data['action'], $data['remarks'] ?? null, $data['data'] ?? [], $data['return_kind'] ?? null);

        $message = ['approve' => 'Approved.', 'reject' => 'Rejected.', 'return' => 'Returned to the initiator.', 'abstain' => 'Abstention recorded.', 'complete' => 'Marked as done.', 'fail' => 'Marked as failed.'][$data['action']];

        return redirect()->route('tasks.index')->with('success', $message);
    }

    public function forward(Request $request, WorkflowTask $task): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'exists:users,id'], 'remarks' => ['nullable', 'string', 'max:5000']]);
        $this->engine->forward($task, $request->user(), User::query()->findOrFail($data['to']), $data['remarks'] ?? null);

        return redirect()->route('tasks.index')->with('success', 'Forwarded.');
    }

    public function reassign(Request $request, WorkflowTask $task): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'exists:users,id'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->engine->reassign($task, $request->user(), User::query()->findOrFail($data['to']), $data['reason']);

        return back()->with('success', 'Task reassigned.');
    }
}
