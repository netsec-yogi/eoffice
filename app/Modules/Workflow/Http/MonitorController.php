<?php

namespace App\Modules\Workflow\Http;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Administrative and departmental monitoring of all requests (WF-017, ADMIN-003). */
class MonitorController extends Controller
{
    public function __construct(private InstancePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can(Permissions::WORKFLOWS_MONITOR) || $user->can(Permissions::WORKFLOWS_MONITOR_DEPARTMENT), 403);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['in_progress', 'returned', 'on_hold', 'completed', 'rejected', 'cancelled', 'overdue'])],
            'workflow' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        // Department monitors see their own unit tree only.
        $scope = $user->can(Permissions::WORKFLOWS_MONITOR) ? null : ($user->department?->descendantIds() ?? [0]);
        $base = fn (): Builder => WorkflowInstance::query()->where('status', '!=', 'draft')->when($scope, fn ($q) => $q->whereIn('department_id', $scope));

        $instances = $base()
            ->with(['definition:id,name', 'initiator:id,name', 'department:id,code', 'tasks' => fn ($q) => $q->where('status', 'pending')->with('assignee:id,name')])
            ->when(($filters['status'] ?? null) === 'overdue', fn ($q) => $q->whereHas('tasks', fn ($t) => $t->where('status', 'pending')->where('due_at', '<', now())))
            ->when(($filters['status'] ?? null) && $filters['status'] !== 'overdue', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['workflow'] ?? null, fn ($q, $w) => $q->where('workflow_definition_id', $w))
            ->when($filters['department'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('reference', 'ilike', "%{$t}%")->orWhere('title', 'ilike', "%{$t}%")))
            ->latest('updated_at')->paginate(25)->withQueryString()
            ->through(fn ($i) => $this->presenter->summary($i));

        $open = $base()->whereIn('status', WorkflowInstance::OPEN);
        $pendingTasks = WorkflowTask::query()->where('status', 'pending')->whereHas('instance', fn ($q) => $q->where('status', 'in_progress')->when($scope, fn ($q) => $q->whereIn('department_id', $scope)));
        $finished = $base()->whereNotNull('completed_at')->whereNotNull('submitted_at')->where('completed_at', '>=', now()->subDays(90));

        return Inertia::render('Monitor/Index', [
            'instances' => $instances,
            'filters' => $filters,
            'stats' => [
                'open' => (clone $open)->count(),
                'overdue_tasks' => (clone $pendingTasks)->where('due_at', '<', now())->count(),
                'escalated_tasks' => (clone $pendingTasks)->where('escalation_level', '>', 0)->count(),
                'returned' => $base()->where('status', 'returned')->count(),
                'completed_90d' => (clone $finished)->where('status', 'completed')->count(),
                'rejected_90d' => (clone $finished)->where('status', 'rejected')->count(),
                'avg_days_90d' => round((float) (clone $finished)->selectRaw('avg(extract(epoch from (completed_at - submitted_at)) / 86400) as d')->value('d'), 1),
            ],
            'workload' => (clone $pendingTasks)->selectRaw('assignee_id, count(*) as n, sum(case when due_at < now() then 1 else 0 end) as overdue')
                ->groupBy('assignee_id')->orderByDesc('n')->limit(10)->with('assignee:id,name')->get()
                ->map(fn ($r) => ['name' => $r->assignee?->name, 'open' => (int) $r->n, 'overdue' => (int) $r->overdue]),
            'workflows' => WorkflowDefinition::query()->orderBy('name')->get(['id', 'name']),
            'departments' => $scope ? Department::query()->whereKey($scope)->orderBy('name')->get(['id', 'code', 'name']) : Department::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
