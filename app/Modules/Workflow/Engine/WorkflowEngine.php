<?php

namespace App\Modules\Workflow\Engine;

use App\Modules\Audit\AuditLogger;
use App\Modules\Calendar\Services\BusinessTime;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Notifications\TemplateRenderer;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Forms\FormDataValidator;
use App\Modules\Workflow\Jobs\RunAutomation;
use App\Modules\Workflow\Models\FormSubmission;
use App\Modules\Workflow\Models\FormVersion;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowAction;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowToken;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Rules\RuleEvaluator;
use App\Support\SequenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one workflow runtime (WF-005 .. WF-016). Every state change of every
 * departmental workflow goes through here:
 *
 *  - each command runs in a transaction holding a row lock on the instance,
 *    so two people acting on the same request are serialised and the second
 *    sees the task already decided (WF-012);
 *  - decisions are appended to workflow_actions and the audit log;
 *  - notifications are written in the same transaction and delivered after
 *    commit; automation runs on the queue after commit -- neither can undo
 *    a recorded decision.
 */
class WorkflowEngine
{
    private ?WorkflowVersion $version = null;

    public function __construct(
        private AssigneeResolver $assignees,
        private DelegationService $delegations,
        private FormDataValidator $forms,
        private RuleEvaluator $rules,
        private BusinessTime $time,
        private SequenceGenerator $sequences,
        private NotificationDispatcher $notifications,
        private TemplateRenderer $renderer,
        private DocumentStore $documents,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Starting and submitting
    // ------------------------------------------------------------------

    /**
     * Create a request on the definition's current published version.
     * With $submit=false it is saved as a draft (no validation, no number).
     */
    public function start(WorkflowDefinition $definition, User $initiator, array $input, bool $submit = true, ?Model $subject = null, bool $system = false, array $variables = []): WorkflowInstance
    {
        $version = $definition->currentVersion()->with(['nodes', 'transitions', 'formVersion'])->first();
        if (! $version || ! $definition->is_active) {
            throw new WorkflowException('This workflow is not available.');
        }
        // A request opened by another workflow (system) is on the person's
        // behalf and skips initiator eligibility; it still needs every approval.
        if (! $system && ! $this->canStart($version, $initiator)) {
            throw new WorkflowException('You are not eligible to start this workflow.', 403);
        }

        return DB::transaction(function () use ($definition, $version, $initiator, $input, $submit, $subject, $variables) {
            $previous = [$this->version, $this->currentInstance];
            $instance = new WorkflowInstance;
            $instance->forceFill([
                'workflow_definition_id' => $definition->id,
                'workflow_version_id' => $version->id,
                'form_version_id' => $version->form_version_id,
                'initiator_id' => $initiator->id,
                'department_id' => $initiator->department_id,
                'title' => $definition->name,
                'status' => 'draft',
                'data' => $this->draftData($version, $input),
                'variables' => $variables,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ])->save();

            [$this->version, $this->currentInstance] = [$version, $instance];
            try {
                $submit ? $this->submitLocked($instance, $initiator, $input) : $this->record($instance, 'draft_saved', $initiator);
            } finally {
                [$this->version, $this->currentInstance] = $previous;
            }

            return $instance;
        });
    }

    /** Submit a draft. */
    public function submit(WorkflowInstance $instance, User $actor, array $input): WorkflowInstance
    {
        return $this->locked($instance, function (WorkflowInstance $instance) use ($actor, $input) {
            if ($instance->status !== 'draft' || $instance->initiator_id !== $actor->id) {
                throw new WorkflowException('Only the initiator can submit a draft.', 403);
            }
            $this->submitLocked($instance, $actor, $input);

            return $instance;
        });
    }

    /** Save changes to a draft without submitting. */
    public function saveDraft(WorkflowInstance $instance, User $actor, array $input): WorkflowInstance
    {
        return $this->locked($instance, function (WorkflowInstance $instance) use ($actor, $input) {
            if ($instance->status !== 'draft' || $instance->initiator_id !== $actor->id) {
                throw new WorkflowException('Only the initiator can edit a draft.', 403);
            }
            $instance->forceFill(['data' => $this->draftData($this->version, $input)])->save();

            return $instance;
        });
    }

    public function canStart(WorkflowVersion $version, User $user): bool
    {
        $rule = $version->setting('initiators', []);
        // Query, never load a partial relation onto the shared request user.
        $departmentCode = fn () => $user->department_id ? Department::query()->whereKey($user->department_id)->value('code') : null;

        return $user->canSignIn()
            && (empty($rule['categories']) || in_array($user->category, $rule['categories'], true))
            && (empty($rule['roles']) || $user->hasAnyRole($rule['roles']))
            && (empty($rule['departments']) || in_array($departmentCode(), $rule['departments'], true));
    }

    private function submitLocked(WorkflowInstance $instance, User $actor, array $input): void
    {
        $data = $this->validateForm($instance, $actor, $input);
        $definition = $instance->definition()->first();

        $reference = $this->sequences->format('WF:'.$definition->key, str_replace('{KEY}', strtoupper($definition->key), $definition->numbering_pattern));
        $instance->forceFill([
            'reference' => $reference,
            'data' => $data,
            'revision' => 1,
            'submitted_at' => now(),
        ]);
        $instance->title = $this->title($instance);
        $this->transition($instance, 'in_progress');
        $instance->save();

        $this->storeSubmission($instance, $actor, $data);
        $this->record($instance, 'submitted', $actor, data: ['revision' => 1]);
        $this->audit->record('workflow.submitted', $instance, ['reference' => $reference, 'version' => $this->version->version], actor: $actor);
        $this->notify('workflow.submitted', [$instance->initiator()->first()], $instance, [], "instance:{$instance->id}:submitted");

        $token = $this->newToken($instance, $this->version->startNode()->key);
        $this->advance($instance, $token, 'default');
        $id = $instance->id;
        DB::afterCommit(fn () => event(new WorkflowSubmitted($id)));
    }

    // ------------------------------------------------------------------
    // Decisions on tasks
    // ------------------------------------------------------------------

    /**
     * approve / reject / abstain (committee) / return (for clarification
     * or documents) / complete & fail (manual steps).
     *
     * @param  array<string, mixed>  $input  form fields editable at this step
     */
    public function decide(WorkflowTask $task, User $actor, string $action, ?string $remarks = null, array $input = [], ?string $returnKind = null): WorkflowInstance
    {
        return $this->locked($task->instance, function (WorkflowInstance $instance) use ($task, $actor, $action, $remarks, $input, $returnKind) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            $onBehalfOf = $this->authorizeTask($task, $instance, $actor, $action);
            $node = $this->version->node($task->node_key);

            if (in_array($action, $node->cfg('require_remarks_on', ['reject', 'return']), true) && trim((string) $remarks) === '') {
                throw new WorkflowException('Please give your remarks.', 422, ['remarks' => 'Remarks are required for this action.']);
            }

            // Fields this step may edit (e.g. "approved amount").
            if ($this->editableFieldsAt($node) !== [] && $input !== []) {
                $data = $this->forms->validate($this->schema($instance), $input, $actor, $node->key, $instance->data, $instance->initiator()->first());
                $instance->forceFill(['data' => array_replace($instance->data, $data)])->save();
            }

            if ($action === 'return') {
                $this->completeTask($task, $actor, 'returned');
                $this->record($instance, 'returned', $actor, $task, $remarks, $onBehalfOf, isDecision: true, data: ['kind' => $returnKind ?? 'clarification']);
                $this->returnToInitiator($instance, $task, $actor, $remarks, $returnKind);

                return $instance;
            }

            $outcome = match ($action) {
                'approve', 'complete' => $action === 'complete' ? 'success' : 'approve',
                'reject', 'fail' => $action === 'fail' ? 'failure' : 'reject',
                'abstain' => 'abstain',
                default => throw new WorkflowException("Unknown action [{$action}]."),
            };
            $this->completeTask($task, $actor, $outcome);
            $this->record($instance, $this->pastTense($action), $actor, $task, $remarks, $onBehalfOf, isDecision: true);
            $this->audit->record("workflow.task.{$action}", $instance, ['task_id' => $task->id, 'node' => $task->node_key], actor: $actor, onBehalfOf: $onBehalfOf);

            $nodeOutcome = $this->nodeOutcome($task, $node, $outcome);
            if ($nodeOutcome !== null) {
                $this->closeSiblings($task, 'superseded');
                $token = WorkflowToken::query()->lockForUpdate()->findOrFail($task->workflow_token_id);
                $this->advance($instance, $token, $nodeOutcome);
            }

            return $instance;
        });
    }

    /** Pass a task to another person, where the step allows it. */
    public function forward(WorkflowTask $task, User $actor, User $to, ?string $remarks): WorkflowInstance
    {
        return $this->locked($task->instance, function (WorkflowInstance $instance) use ($task, $actor, $to, $remarks) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            $onBehalfOf = $this->authorizeTask($task, $instance, $actor, 'forward');
            $this->guardAssignable($instance, $to, $this->version->node($task->node_key));
            $this->moveTask($task, $actor, $to, 'forwarded');
            $this->record($instance, 'forwarded', $actor, $task, $remarks, $onBehalfOf, data: ['to' => $to->id, 'to_name' => $to->name]);

            return $instance;
        });
    }

    /** A supervisor moves someone's task to another person. */
    public function reassign(WorkflowTask $task, User $supervisor, User $to, string $reason): WorkflowInstance
    {
        return $this->locked($task->instance, function (WorkflowInstance $instance) use ($task, $supervisor, $to, $reason) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            if (! $task->isPending() || ! $instance->isOpen()) {
                throw new WorkflowException('This task is no longer open.', 409);
            }
            if (! $supervisor->can('workflows.intervene')) {
                throw new WorkflowException('You may not reassign tasks.', 403);
            }
            $this->guardAssignable($instance, $to, $this->version->node($task->node_key));
            $from = $task->assignee_id;
            $this->moveTask($task, $supervisor, $to, 'reassigned');
            $this->record($instance, 'reassigned', $supervisor, $task, $reason, data: ['from' => $from, 'to' => $to->id, 'to_name' => $to->name]);
            $this->audit->record('workflow.task.reassigned', $instance, ['task_id' => $task->id, 'from' => $from, 'to' => $to->id], actor: $supervisor);

            return $instance;
        });
    }

    /** Escalation by the SLA monitor: the task moves to another person. */
    public function systemReassign(WorkflowTask $task, User $to, string $reason): void
    {
        $this->locked($task->instance()->first(), function (WorkflowInstance $instance) use ($task, $to, $reason) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($task->id);
            if (! $task->isPending() || $instance->status !== 'in_progress' || ! $to->canSignIn() || $to->id === $instance->initiator_id) {
                return;
            }
            $from = $task->assignee_id;
            $task->forceFill(['status' => 'superseded', 'outcome' => 'escalated', 'completed_at' => now()])->save();
            $new = $this->createTask($instance, $task->token()->first(), $this->version->node($task->node_key), $to, null, ['type' => 'escalation'], $task->kind, null);
            $new->forceFill(['committee_id' => $task->committee_id, 'voting_weight' => $task->voting_weight, 'escalation_level' => $task->escalation_level])->save();
            $this->record($instance, 'reassigned', null, $task, $reason, data: ['from' => $from, 'to' => $to->id, 'to_name' => $to->name, 'escalation' => true]);
        });
    }

    // ------------------------------------------------------------------
    // Instance-level commands
    // ------------------------------------------------------------------

    /** The initiator answers a return for clarification. */
    public function resubmit(WorkflowInstance $instance, User $actor, array $input, ?string $remarks = null): WorkflowInstance
    {
        return $this->locked($instance, function (WorkflowInstance $instance) use ($actor, $input, $remarks) {
            if ($instance->status !== 'returned' || $instance->initiator_id !== $actor->id) {
                throw new WorkflowException('Only the initiator can resubmit a returned request.', 403);
            }
            $data = $this->validateForm($instance, $actor, $input, $instance->data);
            $revision = $instance->revision + 1;
            $instance->forceFill(['data' => $data, 'revision' => $revision]);
            $instance->title = $this->title($instance);
            $this->transition($instance, 'in_progress');
            $resumeAt = $instance->return_to_node ?? $this->version->startNode()->key;
            $instance->return_to_node = null;
            $instance->save();

            $this->storeSubmission($instance, $actor, $data);
            $this->record($instance, 'resubmitted', $actor, remarks: $remarks, data: ['revision' => $revision]);

            $token = $this->newToken($instance, $resumeAt);
            $node = $this->version->node($resumeAt);
            $node->type === 'start' ? $this->advance($instance, $token, 'default') : $this->enter($instance, $token, $node);

            return $instance;
        });
    }

    public function hold(WorkflowInstance $instance, User $actor, string $reason): WorkflowInstance
    {
        return $this->locked($instance, function (WorkflowInstance $instance) use ($actor, $reason) {
            $onBehalfOf = null;
            if (! $actor->can('workflows.intervene')) {
                $task = $this->pendingTasksOf($instance, $actor)->first(fn ($t) => in_array('hold', $this->version->node($t->node_key)->allowedActions(), true));
                $onBehalfOf = $task ? $this->authorizeTask($task, $instance, $actor, 'hold') : throw new WorkflowException('You may not put this request on hold.', 403);
            }
            $this->transition($instance, 'on_hold');
            $instance->on_hold_since = now();
            $instance->save();
            $this->record($instance, 'held', $actor, remarks: $reason, onBehalfOf: $onBehalfOf);
            $this->audit->record('workflow.held', $instance, [], actor: $actor);

            return $instance;
        });
    }

    public function resume(WorkflowInstance $instance, User $actor, ?string $remarks = null): WorkflowInstance
    {
        return $this->locked($instance, function (WorkflowInstance $instance) use ($actor, $remarks) {
            $heldBy = $instance->actions()->where('action', 'held')->reorder()->orderByDesc('id')->first();
            $mayResume = $actor->can('workflows.intervene') || ($heldBy && in_array($actor->id, [$heldBy->actor_id, $heldBy->on_behalf_of_id], true));
            if (! $mayResume) {
                throw new WorkflowException('You may not resume this request.', 403);
            }
            // The SLA clock stops while on hold: push pending deadlines forward.
            $pausedSeconds = (int) $instance->on_hold_since?->diffInSeconds(now());
            WorkflowTask::query()->where('workflow_instance_id', $instance->id)->where('status', 'pending')->whereNotNull('due_at')
                ->get()->each(fn (WorkflowTask $t) => $t->forceFill(['due_at' => $t->due_at->addSeconds($pausedSeconds)])->save());
            $this->transition($instance, 'in_progress');
            $instance->on_hold_since = null;
            $instance->save();
            $this->record($instance, 'resumed', $actor, remarks: $remarks, data: ['paused_seconds' => $pausedSeconds]);

            return $instance;
        });
    }

    public function cancel(WorkflowInstance $instance, User $actor, string $reason): WorkflowInstance
    {
        return $this->locked($instance, function (WorkflowInstance $instance) use ($actor, $reason) {
            $byInitiator = $instance->initiator_id === $actor->id && ($instance->status === 'draft' || $this->version->setting('initiator_can_cancel', true));
            if (! $byInitiator && ! $actor->can('workflows.intervene')) {
                throw new WorkflowException('You may not cancel this request.', 403);
            }
            $this->finish($instance, 'cancelled', 'cancelled');
            $this->record($instance, 'cancelled', $actor, remarks: $reason);
            $this->audit->record('workflow.cancelled', $instance, ['by_initiator' => $byInitiator], actor: $actor);
            if ($instance->reference) {
                $this->notify('workflow.cancelled', $this->stakeholders($instance)->reject(fn ($u) => $u->is($actor)), $instance, ['remarks' => $reason], "instance:{$instance->id}:cancelled");
            }

            return $instance;
        });
    }

    // ------------------------------------------------------------------
    // Called by background jobs
    // ------------------------------------------------------------------

    public function automationFinished(IntegrationJob $job, AutomationResult $result): void
    {
        $instance = $job->instance()->first();
        if (! $instance) {
            return;
        }
        $this->locked($instance, function (WorkflowInstance $instance) use ($job, $result) {
            $job = IntegrationJob::query()->lockForUpdate()->findOrFail($job->id);
            $token = WorkflowToken::query()->lockForUpdate()->find($job->workflow_token_id);
            $job->forceFill(['status' => $result->status, 'result' => $result->output ?: null, 'last_error' => $result->status === 'succeeded' ? null : $result->message])->save();

            if (! $token || $token->status !== 'waiting' || $token->node_key !== $job->node_key || ! $instance->isOpen()) {
                return; // the request moved on (cancelled, returned); nothing to continue
            }
            $node = $this->version->node($job->node_key);

            if ($result->status === 'succeeded') {
                $instance->forceFill(['variables' => array_replace($instance->variables ?? [], $result->output)])->save();
                $this->record($instance, 'automation_succeeded', null, nodeKey: $node->key, remarks: $result->message, data: ['action' => $job->action, 'job' => $job->uuid]);
                $this->advance($instance, $token, 'success');

                return;
            }

            $this->record($instance, $result->status === 'blocked' ? 'automation_blocked' : 'automation_failed', null, nodeKey: $node->key, remarks: $result->message, data: ['action' => $job->action, 'job' => $job->uuid]);
            $this->audit->record('workflow.automation_'.$result->status, $instance, ['action' => $job->action, 'job' => $job->uuid, 'message' => $result->message], actor: null);

            if ($result->status === 'failed' && $this->version->outgoing($node->key)->contains('outcome', 'failure')) {
                $this->advance($instance, $token, 'failure');

                return;
            }
            // Blocked, or failed without a failure path: a person completes the step.
            $this->createManualTask($instance, $token, $node, (string) $result->message);
        });
    }

    public function fireTimer(WorkflowToken $token): void
    {
        $this->locked($token->instance()->first(), function (WorkflowInstance $instance) use ($token) {
            $token = WorkflowToken::query()->lockForUpdate()->findOrFail($token->id);
            if ($token->status !== 'waiting' || ! $token->wake_at || $token->wake_at->isFuture() || $instance->status !== 'in_progress') {
                return;
            }
            $token->forceFill(['wake_at' => null])->save();
            $this->record($instance, 'timer_fired', null, nodeKey: $token->node_key);
            $this->advance($instance, $token, 'default');
        });
    }

    // ------------------------------------------------------------------
    // Authorization helpers (also used by policies/controllers)
    // ------------------------------------------------------------------

    /**
     * Who may take $action on $task right now. Returns the principal when
     * the actor acts as a delegate, null when acting in their own right.
     *
     * @throws WorkflowException
     */
    public function authorizeTask(WorkflowTask $task, WorkflowInstance $instance, User $actor, string $action): ?User
    {
        if (! $task->isPending()) {
            throw new WorkflowException('This task has already been dealt with.', 409);
        }
        if ($instance->status !== 'in_progress') {
            throw new WorkflowException('The request is '.str_replace('_', ' ', $instance->status).'; no action is possible now.', 409);
        }
        $version = $this->version ?? $instance->version()->with('nodes', 'transitions')->first();
        $node = $version->node($task->node_key);
        $allowed = $task->kind === 'manual' ? ['complete', 'fail'] : $node->allowedActions();
        if (! in_array($action, $allowed, true)) {
            throw new WorkflowException("'{$action}' is not possible at this step.", 422);
        }
        // Separation of duties (WF-019): nobody decides their own request
        // unless the step explicitly allows it.
        if ($actor->id === $instance->initiator_id && ! $node->cfg('allow_self', false) && $task->kind !== 'manual') {
            throw new WorkflowException('You cannot act on your own request.', 403);
        }
        if ($actor->id === $task->assignee_id) {
            return $task->principal_id ? User::query()->find($task->principal_id) : null;
        }
        $assignee = $task->assignee()->first();
        $category = $instance->definition()->value('category');
        if ($assignee && $actor->canSignIn() && $this->delegations->isDelegateOf($actor, $assignee, $category, $instance->department_id, $action)) {
            return $assignee;
        }

        throw new WorkflowException('This task is not assigned to you.', 403);
    }

    /** Tasks $user can act on in $instance (own or as a delegate). */
    public function pendingTasksOf(WorkflowInstance $instance, User $user): Collection
    {
        $principals = $this->delegations->principalsOf($user);

        return $instance->tasks()->where('status', 'pending')
            ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhereIn('assignee_id', $principals))
            ->get();
    }

    // ------------------------------------------------------------------
    // Execution core
    // ------------------------------------------------------------------

    /** Move $token out of its node along the transition(s) for $outcome. */
    private function advance(WorkflowInstance $instance, WorkflowToken $token, string $outcome): void
    {
        $node = $this->version->node($token->node_key);
        $context = $this->ruleContext($instance);
        // Positive outcomes may also leave by an unlabelled ("default")
        // transition; rejection and failure need an explicit route.
        $positive = in_array($outcome, ['default', 'approve', 'success'], true);
        $candidates = $this->version->outgoing($node->key)
            ->filter(fn ($t) => $t->outcome === $outcome || ($positive && $t->outcome === 'default'))
            ->sortBy([['priority', 'asc'], [fn ($t) => $t->condition === null ? 1 : 0, 'asc'], ['id', 'asc']]);

        if ($node->type === 'parallel_split') {
            $branches = $this->version->outgoing($node->key)->filter(fn ($t) => $this->rules->passes($t->condition, $context))->values();
            if ($branches->isEmpty()) {
                throw new WorkflowException("No branch of '{$node->name}' applies to this request. The workflow definition needs a default branch.");
            }
            $token->forceFill(['status' => 'consumed'])->save();
            foreach ($branches as $t) {
                $child = $this->newToken($instance, $t->to_node, $token->id);
                $this->enter($instance, $child, $this->version->node($t->to_node));
            }

            return;
        }

        $next = $candidates->first(fn ($t) => $this->rules->passes($t->condition, $context));
        if (! $next) {
            throw new WorkflowException("The workflow has no route from '{$node->name}' for outcome '{$outcome}'. Ask the workflow administrator to fix the definition.");
        }

        $this->enter($instance, $token, $this->version->node($next->to_node));
    }

    /** Place $token on $node and perform what the node does on arrival. */
    private function enter(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node): void
    {
        $token->forceFill([
            'node_key' => $node->key,
            'status' => 'active',
            'task_floor' => (int) WorkflowTask::query()->where('workflow_instance_id', $instance->id)->max('id'),
        ])->save();

        switch ($node->type) {
            case 'approval':
            case 'review':
            case 'verification':
            case 'signature':
                $token->forceFill(['status' => 'waiting'])->save();
                $this->createTasks($instance, $token, $node);
                break;

            case 'committee':
                $token->forceFill(['status' => 'waiting'])->save();
                $this->createCommitteeTasks($instance, $token, $node);
                break;

            case 'decision':
            case 'start':
                $this->advance($instance, $token, 'default');
                break;

            case 'parallel_split':
                $this->advance($instance, $token, 'default');
                break;

            case 'parallel_join':
                $this->join($instance, $token, $node);
                break;

            case 'notification':
                $recipients = collect($node->cfg('to', [['type' => 'initiator']]))->flatMap(fn ($spec) => $this->assignees->resolve($spec, $instance));
                $this->notify((string) $node->cfg('template'), $recipients, $instance, [], "instance:{$instance->id}:node:{$node->key}:rev:{$instance->revision}");
                $this->advance($instance, $token, 'default');
                break;

            case 'timer':
                $wake = $this->time->add(now(), (float) $node->cfg('duration', 1), (string) $node->cfg('unit', 'working_days'), $node->cfg('calendar'));
                $token->forceFill(['status' => 'waiting', 'wake_at' => $wake])->save();
                break;

            case 'automation':
                $token->forceFill(['status' => 'waiting'])->save();
                $this->queueAutomation($instance, $token, $node);
                break;

            case 'end':
                $token->forceFill(['status' => 'consumed'])->save();
                $outcome = $node->cfg('outcome', 'approved');
                $status = ['approved' => 'completed', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$outcome] ?? 'completed';
                $this->finish($instance, $status, $outcome);
                $this->record($instance, $status, null, nodeKey: $node->key);
                $this->audit->record("workflow.{$status}", $instance, ['outcome' => $outcome], actor: null);
                $template = ['approved' => 'workflow.approved', 'rejected' => 'workflow.rejected', 'cancelled' => 'workflow.cancelled'][$outcome] ?? 'workflow.approved';
                $remarks = $instance->actions()->where('is_decision', true)->reorder()->orderByDesc('id')->value('remarks');
                $this->notify($template, [$instance->initiator()->first()], $instance, ['remarks' => $remarks ?? ''], "instance:{$instance->id}:{$status}");
                break;

            default:
                throw new WorkflowException("Unknown node type [{$node->type}].");
        }
    }

    /** Parallel join (WF-008): continue when all (or any) branches arrive. */
    private function join(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node): void
    {
        $token->forceFill(['status' => 'joined'])->save();
        if (! $token->parent_token_id) {
            // Not inside a split: a join with one incoming path is a pass-through.
            $token->forceFill(['status' => 'active'])->save();
            $this->advance($instance, $token, 'default');

            return;
        }

        $siblings = WorkflowToken::query()->where('parent_token_id', $token->parent_token_id)->lockForUpdate()->get();
        $live = $siblings->whereNotIn('status', ['cancelled']);
        $arrived = $live->where('status', 'joined')->where('node_key', $node->key);
        $waitAny = $node->cfg('wait', 'all') === 'any';

        if (! $waitAny && $arrived->count() < $live->count()) {
            return; // other branches still running
        }

        // Close the branches and continue on the parent token.
        foreach ($live as $sibling) {
            if ($sibling->status !== 'joined' || $sibling->node_key !== $node->key) {
                $this->cancelToken($sibling, 'superseded');
            } else {
                $sibling->forceFill(['status' => 'consumed'])->save();
            }
        }
        $parent = WorkflowToken::query()->lockForUpdate()->findOrFail($token->parent_token_id);
        $parent->forceFill(['node_key' => $node->key, 'status' => 'active'])->save();
        $this->advance($instance, $parent, 'default');
    }

    private function createTasks(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node): void
    {
        $assignees = $this->resolveAssignees($instance, $node);
        $due = $this->dueAt($node);
        $kind = $node->type === 'signature' ? 'signature' : $node->type;

        foreach ($assignees as [$user, $principal, $via]) {
            $this->createTask($instance, $token, $node, $user, $principal, $via, $kind, $due);
        }
    }

    private function createCommitteeTasks(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node): void
    {
        $members = $this->assignees->committeeMembers((string) $node->cfg('committee'));
        $due = $this->dueAt($node);
        $category = $instance->definition()->value('category');
        $created = 0;

        foreach ($members as $member) {
            $user = $member->user;
            $principal = null;
            // Delegation applies to committee votes only if the delegation covers "approve".
            $delegate = $this->delegations->delegateFor($user, $category, $instance->department_id, 'approve');
            if ($delegate && $delegate->id !== $instance->initiator_id) {
                [$user, $principal] = [$delegate, $member->user];
            }
            if ($user->id === $instance->initiator_id && ! $node->cfg('allow_self', false)) {
                continue; // a member does not vote on their own request
            }
            $task = $this->createTask($instance, $token, $node, $user, $principal, ['type' => 'committee', 'committee' => $node->cfg('committee'), 'role' => $member->role], 'committee_vote', $due);
            $task->forceFill(['committee_id' => $member->committee_id, 'voting_weight' => $member->voting_weight])->save();
            $created++;
        }

        if ($created === 0) {
            // Nobody can vote: hand the step to the fallback instead of stalling.
            $this->createTasks($instance, $token, $node);
        }
    }

    private function createTask(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node, User $user, ?User $principal, array $via, string $kind, $due): WorkflowTask
    {
        $task = new WorkflowTask;
        $task->forceFill([
            'workflow_instance_id' => $instance->id,
            'workflow_token_id' => $token->id,
            'node_key' => $node->key,
            'name' => $node->name,
            'kind' => $kind,
            'assignee_id' => $user->id,
            'principal_id' => $principal?->id,
            'assigned_via' => $via,
            'status' => 'pending',
            'due_at' => $due,
        ])->save();

        $this->record($instance, 'assigned', null, $task, data: ['assignee' => $user->id, 'assignee_name' => $user->name, 'on_behalf_of' => $principal?->name, 'via' => $this->assignees->describe($via)]);
        $this->notify('workflow.task_assigned', [$user], $instance, ['task' => $this->taskContext($task)], "task:{$task->id}:assigned", route('tasks.show', $task->id, false));

        return $task;
    }

    /**
     * People for a human node, after separation of duties, delegation and
     * fallback. Never empty: an unresolvable step goes to the fallback, then
     * to workflow supervisors, and is audited (docs/ARCHITECTURE.md §4.4).
     *
     * @return list<array{0: User, 1: ?User, 2: array}>
     */
    private function resolveAssignees(WorkflowInstance $instance, WorkflowNode $node): array
    {
        $category = $instance->definition()->value('category');
        $pick = function (array $specs) use ($instance, $node, $category): array {
            $out = [];
            foreach ($specs as $spec) {
                foreach ($this->assignees->resolve($spec, $instance) as $user) {
                    $principal = null;
                    // A delegation never hands someone their own request:
                    // then the principal keeps the task.
                    $delegate = $this->delegations->delegateFor($user, $category, $instance->department_id, 'approve');
                    if ($delegate && $delegate->id !== $instance->initiator_id) {
                        [$user, $principal] = [$delegate, $user];
                    }
                    if ($user->id === $instance->initiator_id && ! $node->cfg('allow_self', false)) {
                        continue;
                    }
                    $out[$user->id] ??= [$user, $principal, $spec];
                }
            }

            return array_values($out);
        };

        $resolved = $pick($node->cfg('assignees', []));
        if ($resolved !== []) {
            return $resolved;
        }

        $this->audit->record('workflow.assignment_unresolved', $instance, ['node' => $node->key, 'specs' => $node->cfg('assignees', [])], outcome: 'failure', actor: null);
        $fallback = $pick(array_filter([$this->version->setting('fallback')]));
        if ($fallback !== []) {
            return $fallback;
        }
        $supervisors = User::permission('workflows.intervene')->get()->filter->canSignIn()
            ->reject(fn ($u) => $u->id === $instance->initiator_id)
            ->map(fn ($u) => [$u, null, ['type' => 'role', 'role' => 'workflow supervisor']])->values()->all();
        if ($supervisors === []) {
            throw new WorkflowException("Nobody can be assigned to '{$node->name}': no holder, no fallback and no workflow supervisor. Ask an administrator to assign the authority.");
        }

        return $supervisors;
    }

    /** Has the node reached a decision after this task completed? null = keep waiting. */
    private function nodeOutcome(WorkflowTask $task, WorkflowNode $node, string $outcome): ?string
    {
        $floor = (int) WorkflowToken::query()->whereKey($task->workflow_token_id)->value('task_floor');
        $tasks = WorkflowTask::query()->where('workflow_token_id', $task->workflow_token_id)->where('node_key', $node->key)
            ->whereIn('status', ['pending', 'completed'])->where('id', '>', $floor)->get();

        if ($task->kind === 'manual') {
            return $outcome;
        }
        if ($node->type === 'committee') {
            $votes = $tasks->where('kind', 'committee_vote')->map(fn ($t) => [
                'vote' => $t->status === 'completed' ? $t->outcome : null,
                'weight' => (float) ($t->voting_weight ?? 1),
            ])->values()->all();

            return CommitteeRule::decide((string) $node->cfg('rule', 'majority'), $votes, $node->cfg('quorum') !== null ? (int) $node->cfg('quorum') : null, $node->cfg('threshold') !== null ? (float) $node->cfg('threshold') : null);
        }

        // Several assignees: "any" decides on the first answer, "all" needs
        // every approval and is rejected by any rejection.
        if ($node->cfg('mode', 'any') === 'all' && $outcome === 'approve') {
            return $tasks->where('status', 'pending')->isEmpty() ? 'approve' : null;
        }

        return $outcome;
    }

    private function returnToInitiator(WorkflowInstance $instance, WorkflowTask $task, User $actor, ?string $remarks, ?string $kind): void
    {
        $token = WorkflowToken::query()->findOrFail($task->workflow_token_id);
        // Inside a parallel branch the other branches cannot be resumed
        // consistently, so the request restarts from the beginning.
        $instance->return_to_node = $token->parent_token_id ? $this->version->startNode()->key : $task->node_key;

        foreach (WorkflowToken::query()->where('workflow_instance_id', $instance->id)->whereIn('status', ['active', 'waiting', 'joined'])->lockForUpdate()->get() as $t) {
            $this->cancelToken($t, 'cancelled');
        }
        $this->transition($instance, 'returned');
        $instance->save();

        $this->audit->record('workflow.returned', $instance, ['node' => $task->node_key, 'kind' => $kind ?? 'clarification'], actor: $actor);
        $this->notify('workflow.returned', [$instance->initiator()->first()], $instance, ['remarks' => (string) $remarks, 'actor' => ['name' => $actor->name]], "instance:{$instance->id}:returned:{$instance->revision}", route('requests.show', $instance->id, false));
    }

    private function queueAutomation(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node): void
    {
        $job = new IntegrationJob;
        $job->forceFill([
            'uuid' => (string) Str::uuid(),
            'workflow_instance_id' => $instance->id,
            'workflow_token_id' => $token->id,
            'node_key' => $node->key,
            'action' => (string) $node->cfg('action'),
            // Same instance, node and revision => same job; a replay cannot duplicate the effect.
            'idempotency_key' => "wf:{$instance->id}:{$node->key}:rev{$instance->revision}:tok{$token->id}",
            'status' => 'pending',
            'input' => $node->cfg('settings', []),
            'correlation_id' => Context::get('correlation_id'),
        ])->save();
        $this->record($instance, 'automation_queued', null, nodeKey: $node->key, data: ['action' => $job->action, 'job' => $job->uuid]);

        RunAutomation::dispatch($job->id)->onQueue('integrations')->afterCommit();
    }

    private function createManualTask(WorkflowInstance $instance, WorkflowToken $token, WorkflowNode $node, string $reason): void
    {
        // The step's own handler first; the workflow fallback only if nobody is found.
        $users = collect();
        foreach (array_filter([$node->cfg('on_blocked'), $this->version->setting('fallback')]) as $spec) {
            $users = $this->assignees->resolve($spec, $instance)->reject(fn ($u) => $u->id === $instance->initiator_id)->values();
            if ($users->isNotEmpty()) {
                break;
            }
        }
        if ($users->isEmpty()) {
            $users = User::permission('workflows.intervene')->get()->filter->canSignIn();
        }
        foreach ($users as $user) {
            $task = $this->createTask($instance, $token, $node, $user, null, ['type' => 'manual'], 'manual', $this->dueAt($node));
            $this->notify('workflow.integration_blocked', [$user], $instance, ['task' => $this->taskContext($task), 'error' => $reason], "task:{$task->id}:blocked");
        }
    }

    private function moveTask(WorkflowTask $task, User $actor, User $to, string $why): void
    {
        $task->forceFill(['status' => 'superseded', 'outcome' => $why, 'completed_at' => now(), 'completed_by' => $actor->id])->save();
        $node = $this->version->node($task->node_key);
        $instance = $task->instance()->first();
        $new = $this->createTask($instance, $task->token()->first(), $node, $to, null, ['type' => 'user', 'user_id' => $to->id, 'via' => $why], $task->kind, $task->due_at);
        $new->forceFill(['committee_id' => $task->committee_id, 'voting_weight' => $task->voting_weight])->save();
    }

    private function guardAssignable(WorkflowInstance $instance, User $to, WorkflowNode $node): void
    {
        if (! $to->canSignIn()) {
            throw new WorkflowException('That person cannot sign in and cannot be given work.', 422);
        }
        if ($to->id === $instance->initiator_id && ! $node->cfg('allow_self', false)) {
            throw new WorkflowException('A request cannot be sent to its own initiator for a decision.', 422);
        }
    }

    private function completeTask(WorkflowTask $task, User $actor, string $outcome): void
    {
        $task->forceFill(['status' => 'completed', 'outcome' => $outcome, 'completed_at' => now(), 'completed_by' => $actor->id])->save();
    }

    private function closeSiblings(WorkflowTask $task, string $status): void
    {
        WorkflowTask::query()->where('workflow_token_id', $task->workflow_token_id)->where('status', 'pending')->whereKeyNot($task->id)
            ->update(['status' => $status, 'completed_at' => now(), 'updated_at' => now()]);
    }

    private function cancelToken(WorkflowToken $token, string $taskStatus): void
    {
        $token->forceFill(['status' => 'cancelled', 'wake_at' => null])->save();
        WorkflowTask::query()->where('workflow_token_id', $token->id)->where('status', 'pending')
            ->update(['status' => $taskStatus, 'completed_at' => now(), 'updated_at' => now()]);
        WorkflowToken::query()->where('parent_token_id', $token->id)->whereIn('status', ['active', 'waiting', 'joined'])->get()
            ->each(fn ($child) => $this->cancelToken($child, $taskStatus));
    }

    private function finish(WorkflowInstance $instance, string $status, string $outcome): void
    {
        foreach (WorkflowToken::query()->where('workflow_instance_id', $instance->id)->whereIn('status', ['active', 'waiting', 'joined'])->get() as $t) {
            $this->cancelToken($t, 'cancelled');
        }
        $this->transition($instance, $status);
        $instance->forceFill(['outcome' => $outcome, 'completed_at' => now(), 'on_hold_since' => null])->save();
        // Modules that own the request's subject (e.g. an e-file) react after commit.
        $id = $instance->id;
        DB::afterCommit(fn () => event(new WorkflowFinished($id, $status, $outcome)));
    }

    private function transition(WorkflowInstance $instance, string $to): void
    {
        $from = $instance->status;
        if (! in_array($to, WorkflowInstance::TRANSITIONS[$from] ?? [], true)) {
            throw new WorkflowException("A request cannot go from {$from} to {$to}.", 409);
        }
        $instance->status = $to;
        $instance->lock_version++;
    }

    private function newToken(WorkflowInstance $instance, string $nodeKey, ?int $parent = null): WorkflowToken
    {
        $token = new WorkflowToken;
        $token->forceFill(['workflow_instance_id' => $instance->id, 'node_key' => $nodeKey, 'status' => 'active', 'parent_token_id' => $parent])->save();

        return $token;
    }

    private function dueAt(WorkflowNode $node)
    {
        // A statutory or requested date on the form (e.g. RTI reply due) wins.
        $field = $node->cfg('sla.due_field');
        $value = $field ? data_get($this->currentInstance?->data ?? [], $field) : null;
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return CarbonImmutable::parse($value)->endOfDay();
        }
        $duration = $node->cfg('sla.duration');

        return $duration ? $this->time->add(now(), (float) $duration, (string) $node->cfg('sla.unit', 'working_days'), $node->cfg('sla.calendar')) : null;
    }

    // ------------------------------------------------------------------
    // Data helpers
    // ------------------------------------------------------------------

    /** @var array<int, array> */
    private array $schemas = [];

    private function schema(WorkflowInstance $instance): array
    {
        if (! $instance->form_version_id) {
            return ['sections' => []];
        }

        return $this->schemas[$instance->form_version_id] ??= FormVersion::query()->findOrFail($instance->form_version_id)->schema;
    }

    private function validateForm(WorkflowInstance $instance, User $actor, array $input, array $existing = []): array
    {
        $schema = $this->schema($instance);
        if (($schema['sections'] ?? []) === [] && ! $this->version->setting('validator')) {
            return [];
        }
        $initiator = $instance->initiator()->first();
        $data = $this->forms->validate($schema, $input, $actor, null, $existing, $initiator);
        if ($key = $this->version->setting('validator')) {
            $class = (config('workflow.validators')[$key] ?? null) ?? throw new WorkflowException("Validator '{$key}' is not installed.");
            $data = array_replace($data, app($class)->validate($data, $initiator, $instance));
        }

        // Uploaded files now belong to the request.
        foreach ($this->forms->documentIds($schema, $data) as $uuid) {
            if ($document = Document::query()->where('uuid', $uuid)->first()) {
                $this->documents->attach($document, $instance);
            }
        }

        return $data;
    }

    private function draftData(WorkflowVersion $version, array $input): array
    {
        $schema = $version->formVersion?->schema ?? ['sections' => []];
        $keys = array_column($this->forms->fields($schema), 'key');

        return array_intersect_key($input, array_flip($keys));
    }

    private function storeSubmission(WorkflowInstance $instance, User $actor, array $data): void
    {
        if (! $instance->form_version_id) {
            return;
        }
        (new FormSubmission)->forceFill([
            'workflow_instance_id' => $instance->id,
            'form_version_id' => $instance->form_version_id,
            'revision' => $instance->revision,
            'data' => $data,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ])->save();
    }

    private function editableFieldsAt(WorkflowNode $node): array
    {
        return array_values(array_filter($this->forms->fields($this->schema($this->currentInstance)), fn ($f) => in_array($node->key, $f['editable_at'] ?? [], true)));
    }

    private function ruleContext(WorkflowInstance $instance): array
    {
        return $this->forms->context($instance->data ?? [], $instance->initiator()->first()) + ['vars' => $instance->variables ?? []];
    }

    private function title(WorkflowInstance $instance): string
    {
        $definition = $instance->definition()->first();
        $template = $this->version->setting('title_template');
        $initiator = $instance->initiator()->first();
        $title = $template ? trim((string) $this->renderer->render($template, $instance->data + ['initiator' => ['name' => $initiator->name]])) : '';

        return Str::limit($title !== '' ? $title : "{$definition->name} – {$initiator->name}", 250);
    }

    private function stakeholders(WorkflowInstance $instance): Collection
    {
        $ids = $instance->tasks()->where('status', 'pending')->pluck('assignee_id')->push($instance->initiator_id)->unique();

        return User::query()->whereKey($ids)->get();
    }

    private function taskContext(WorkflowTask $task): array
    {
        return [
            'name' => $task->name,
            'due' => $task->due_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? 'no deadline',
            'assignee' => $task->assignee()->value('name'),
        ];
    }

    /** @param iterable<User> $recipients */
    private function notify(string $template, iterable $recipients, WorkflowInstance $instance, array $extra, string $eventKey, ?string $link = null): void
    {
        try {
            $initiator = $instance->initiator()->first();
            $this->notifications->send($template, $recipients, [
                'instance' => ['reference' => $instance->reference, 'title' => $instance->title, 'initiator' => $initiator?->name],
                ...$extra,
            ], $eventKey, $link ?? route('requests.show', $instance->id, false));
        } catch (Throwable $e) {
            report($e); // a notification problem never blocks the workflow
        }
    }

    private function record(WorkflowInstance $instance, string $action, ?User $actor, ?WorkflowTask $task = null, ?string $remarks = null, ?User $onBehalfOf = null, bool $isDecision = false, array $data = [], ?string $nodeKey = null): void
    {
        (new WorkflowAction)->forceFill([
            'workflow_instance_id' => $instance->id,
            'workflow_task_id' => $task?->id,
            'node_key' => $task?->node_key ?? $nodeKey,
            'actor_id' => $actor?->id,
            'on_behalf_of_id' => $onBehalfOf?->id,
            'action' => $action,
            'is_decision' => $isDecision,
            'to_status' => $instance->status,
            'remarks' => $remarks !== null ? Str::limit(trim($remarks), 5000, '') : null,
            'data' => $data ?: null,
        ])->save();
    }

    private function pastTense(string $action): string
    {
        return ['approve' => 'approved', 'reject' => 'rejected', 'abstain' => 'abstained', 'complete' => 'completed_manually', 'fail' => 'failed_manually'][$action] ?? $action;
    }

    private ?WorkflowInstance $currentInstance = null;

    /** Run $fn with the instance row locked inside a transaction. */
    private function locked(WorkflowInstance $instance, callable $fn): mixed
    {
        return DB::transaction(function () use ($instance, $fn) {
            $fresh = WorkflowInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $previous = [$this->version, $this->currentInstance];
            $this->version = WorkflowVersion::query()->with(['nodes', 'transitions'])->findOrFail($fresh->workflow_version_id);
            $this->currentInstance = $fresh;
            try {
                return $fn($fresh);
            } finally {
                [$this->version, $this->currentInstance] = $previous;
            }
        });
    }
}
