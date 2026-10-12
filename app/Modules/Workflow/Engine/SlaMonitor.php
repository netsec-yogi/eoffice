<?php

namespace App\Modules\Workflow\Engine;

use App\Modules\Audit\AuditLogger;
use App\Modules\Calendar\Services\BusinessTime;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Workflow\Models\WorkflowAction;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reminders and escalations for overdue work (WF-015). Runs from the
 * scheduler, never from a browser. Each reminder and each escalation level
 * is claimed by inserting a unique (task, kind, level) row first, so running
 * the scan twice -- or on two servers -- never sends anything twice.
 *
 * Node configuration:
 *   "sla": {"duration": 3, "unit": "working_days",
 *           "escalations": [{"after": 1, "unit": "working_days", "to": {assignee spec}, "action": "notify"},
 *                           {"after": 3, "unit": "working_days", "to": {...}, "action": "reassign"}]}
 */
class SlaMonitor
{
    public function __construct(
        private AssigneeResolver $assignees,
        private BusinessTime $time,
        private NotificationDispatcher $notifications,
        private WorkflowEngine $engine,
        private AuditLogger $audit,
    ) {}

    /** @return array{reminders: int, escalations: int} */
    public function scan(): array
    {
        $counts = ['reminders' => 0, 'escalations' => 0];

        WorkflowTask::query()
            ->where('status', 'pending')->whereNotNull('due_at')
            ->whereHas('instance', fn ($q) => $q->where('status', 'in_progress'))
            ->with(['instance.initiator', 'instance.version.nodes', 'assignee'])
            ->orderBy('due_at')
            ->chunkById(200, function ($tasks) use (&$counts) {
                foreach ($tasks as $task) {
                    try {
                        $this->remind($task) && $counts['reminders']++;
                        $counts['escalations'] += $this->escalate($task);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return $counts;
    }

    private function remind(WorkflowTask $task): bool
    {
        $lead = (float) config('workflow.reminder_at', 0.75);
        $remindAt = $task->created_at->copy()->addSeconds((int) ($task->created_at->diffInSeconds($task->due_at) * $lead));
        $level = $task->due_at->isPast() ? 2 : ($remindAt->isPast() ? 1 : 0);
        $sent = false;
        for ($l = 1; $l <= $level; $l++) {
            if (! $this->claim($task, 'reminder', $l, [$task->assignee_id])) {
                continue;
            }
            $this->notifications->send('workflow.task_reminder', [$task->assignee], $this->context($task), "task:{$task->id}:reminder:{$l}", route('tasks.show', $task->id, false));
            $sent = true;
        }

        return $sent;
    }

    private function escalate(WorkflowTask $task): int
    {
        $node = $task->instance->version->nodes->firstWhere('key', $task->node_key);
        $levels = $node instanceof WorkflowNode ? $node->cfg('sla.escalations', []) : [];
        $fired = 0;

        foreach (array_values($levels) as $i => $level) {
            $number = $i + 1;
            $at = $this->time->add($task->due_at, (float) ($level['after'] ?? 0), (string) ($level['unit'] ?? 'working_days'));
            if ($at->isFuture() || $task->escalation_level >= $number) {
                continue;
            }
            $recipients = $this->assignees->resolve($level['to'] ?? [], $task->instance)->reject(fn ($u) => $u->id === $task->instance->initiator_id);
            if (! $this->claim($task, 'escalation', $number, $recipients->pluck('id')->all())) {
                continue;
            }
            $fired++;
            $task->forceFill(['escalation_level' => $number])->save();

            (new WorkflowAction)->forceFill([
                'workflow_instance_id' => $task->workflow_instance_id, 'workflow_task_id' => $task->id, 'node_key' => $task->node_key,
                'action' => 'escalated', 'to_status' => $task->instance->status,
                'data' => ['level' => $number, 'to' => $recipients->pluck('name')->all(), 'action' => $level['action'] ?? 'notify'],
            ])->save();
            $this->audit->record('workflow.task.escalated', $task->instance, ['task_id' => $task->id, 'level' => $number, 'recipients' => $recipients->pluck('id')->all()], actor: null);

            $this->notifications->send('workflow.task_escalated', $recipients, $this->context($task) + ['escalation' => ['level' => $number]], "task:{$task->id}:escalation:{$number}", route('requests.show', $task->workflow_instance_id, false));

            if (($level['action'] ?? 'notify') === 'reassign' && ($to = $recipients->first())) {
                $this->engine->systemReassign($task, $to, "Escalation level {$number}: the deadline was missed.");

                break; // the task is closed; later levels belong to the new task
            }
        }

        return $fired;
    }

    /** Insert the (task, kind, level) marker; false if it already existed. */
    private function claim(WorkflowTask $task, string $kind, int $level, array $recipients): bool
    {
        return DB::table('workflow_task_events')->insertOrIgnore([
            'workflow_task_id' => $task->id, 'kind' => $kind, 'level' => $level,
            'recipients' => json_encode(array_values($recipients)), 'fired_at' => now(),
        ]) === 1;
    }

    private function context(WorkflowTask $task): array
    {
        return [
            'instance' => ['reference' => $task->instance->reference, 'title' => $task->instance->title, 'initiator' => $task->instance->initiator->name],
            'task' => ['name' => $task->name, 'due' => $task->due_at->timezone(config('app.timezone'))->format('d M Y, H:i'), 'assignee' => $task->assignee->name],
        ];
    }
}
