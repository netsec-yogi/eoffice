<?php

namespace Tests\Feature\Workflow;

use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Workflow\Engine\DelegationService;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\WorkflowAction;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowTask;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\Support\BuildsWorkflows;
use Tests\TestCase;

/** WF-005 .. WF-019 */
class EngineTest extends TestCase
{
    use BuildsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();
    }

    private function chain(): WorkflowDefinition
    {
        return $this->workflow([
            ['start', 'start', 'Submitted'],
            ['hod', 'approval', 'HoD', ['assignees' => [['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]]],
            ['reg', 'approval', 'Registrar', ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]]],
            ['ok', 'end', 'Approved', ['outcome' => 'approved']],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'hod'], ['hod', 'reg', 'approve'], ['hod', 'no', 'reject'], ['reg', 'ok', 'approve'], ['reg', 'no', 'reject']]);
    }

    public function test_sequential_approval_completes_and_records_history(): void
    {
        $instance = $this->engine()->start($this->chain(), $this->initiator, []);

        $this->assertSame('in_progress', $instance->status);
        $this->assertMatchesRegularExpression('#^TEST/\d{4}/0001$#', $instance->reference);
        $this->assertSame($this->hod->id, $this->pendingTask($instance)->assignee_id, 'routed by authority, not by employee id');

        $this->approve($instance, $this->hod);
        $this->assertSame($this->registrar->id, $this->pendingTask($instance)->assignee_id);
        $this->approve($instance, $this->registrar);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);
        $this->assertSame('approved', $instance->outcome);
        $this->assertSame(['submitted', 'assigned', 'approved', 'assigned', 'approved', 'completed'], $instance->actions()->pluck('action')->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'workflow.completed', 'subject_id' => $instance->id]);
        $this->assertTrue(NotificationMessage::query()->where('user_id', $this->initiator->id)->where('template_key', 'workflow.approved')->exists());
        $this->assertTrue(NotificationMessage::query()->where('user_id', $this->hod->id)->where('template_key', 'workflow.task_assigned')->exists());
    }

    public function test_rejection_needs_remarks_and_ends_the_request(): void
    {
        $instance = $this->engine()->start($this->chain(), $this->initiator, []);
        $task = $this->pendingTask($instance);

        try {
            $this->engine()->decide($task, $this->hod, 'reject', '  ');
            $this->fail('remarks should be required');
        } catch (WorkflowException $e) {
            $this->assertSame(422, $e->status);
        }

        $this->engine()->decide($task, $this->hod, 'reject', 'Budget not available');
        $instance->refresh();
        $this->assertSame('rejected', $instance->status);
        $this->assertSame(0, $instance->tasks()->where('status', 'pending')->count());
        $this->assertSame('Budget not available', $instance->actions()->where('action', 'rejected')->value('remarks'));
    }

    public function test_return_for_clarification_and_resubmission_resume_at_the_same_step(): void
    {
        $formId = $this->form([['key' => 'purpose', 'type' => 'text', 'label' => 'Purpose', 'required' => true]]);
        $definition = $this->workflow([
            ['start', 'start'],
            ['hod', 'approval', 'HoD', ['assignees' => [['type' => 'authority', 'authority' => 'HOD']]]],
            ['reg', 'approval', 'Registrar', ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]]],
            ['ok', 'end', 'Approved'],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'hod'], ['hod', 'reg', 'approve'], ['hod', 'no', 'reject'], ['reg', 'ok', 'approve'], ['reg', 'no', 'reject']], $formId);

        $instance = $this->engine()->start($definition, $this->initiator, ['purpose' => 'Conference']);
        $this->approve($instance, $this->hod);
        $this->engine()->decide($this->pendingTask($instance), $this->registrar, 'return', 'Attach the invitation', returnKind: 'documents');

        $instance->refresh();
        $this->assertSame('returned', $instance->status);
        $this->assertSame(0, $instance->tasks()->where('status', 'pending')->count());

        // Only the initiator may answer.
        try {
            $this->engine()->resubmit($instance, $this->hod, ['purpose' => 'x']);
            $this->fail();
        } catch (WorkflowException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->engine()->resubmit($instance, $this->initiator, ['purpose' => 'Conference in Pune'], 'Invitation attached');
        $instance->refresh();
        $this->assertSame('in_progress', $instance->status);
        $this->assertSame(2, $instance->revision);
        $this->assertSame('Conference in Pune', $instance->data['purpose']);
        $this->assertSame($this->registrar->id, $this->pendingTask($instance)->assignee_id, 'resumes at the step that returned it');
        $this->assertSame(2, $instance->submissions()->count(), 'every revision is kept');
    }

    public function test_conditional_routing_uses_form_data(): void
    {
        $formId = $this->form([['key' => 'amount', 'type' => 'decimal', 'label' => 'Amount', 'required' => true]]);
        $definition = $this->workflow([
            ['start', 'start'],
            ['route', 'decision', 'Amount check'],
            ['dir', 'approval', 'Director', ['assignees' => [['type' => 'authority', 'authority' => 'DIRECTOR']]]],
            ['reg', 'approval', 'Registrar', ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]]],
            ['ok', 'end', 'Approved'],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [
            ['start', 'route'],
            ['route', 'dir', 'default', ['field' => 'amount', 'op' => 'gt', 'value' => 50000], 10],
            ['route', 'reg', 'default', null, 20],
            ['dir', 'ok', 'approve'], ['dir', 'no', 'reject'], ['reg', 'ok', 'approve'], ['reg', 'no', 'reject'],
        ], $formId);

        $big = $this->engine()->start($definition, $this->initiator, ['amount' => '75000']);
        $small = $this->engine()->start($definition, $this->initiator, ['amount' => 1200]);

        $this->assertSame($this->director->id, $this->pendingTask($big)->assignee_id);
        $this->assertSame($this->registrar->id, $this->pendingTask($small)->assignee_id);
    }

    public function test_parallel_branches_must_all_approve(): void
    {
        $finance = $this->person('Finance');
        $tech = $this->person('Tech');
        $definition = $this->workflow([
            ['start', 'start'],
            ['split', 'parallel_split', 'Evaluate'],
            ['fin', 'approval', 'Financial evaluation', ['assignees' => [['type' => 'user', 'user_id' => $finance->id]]]],
            ['tec', 'approval', 'Technical evaluation', ['assignees' => [['type' => 'user', 'user_id' => $tech->id]]]],
            ['join', 'parallel_join', 'Both done'],
            ['dir', 'approval', 'Director', ['assignees' => [['type' => 'authority', 'authority' => 'DIRECTOR']]]],
            ['ok', 'end', 'Approved'],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'split'], ['split', 'fin'], ['split', 'tec'], ['fin', 'join', 'approve'], ['tec', 'join', 'approve'], ['fin', 'no', 'reject'], ['tec', 'no', 'reject'], ['join', 'dir'], ['dir', 'ok', 'approve'], ['dir', 'no', 'reject']]);

        $instance = $this->engine()->start($definition, $this->initiator, []);
        $this->assertSame(2, $instance->tasks()->where('status', 'pending')->count(), 'both evaluations run at once');

        $this->approve($instance, $tech);
        $this->assertSame([$finance->id], $instance->tasks()->where('status', 'pending')->pluck('assignee_id')->all(), 'the join waits');
        $this->approve($instance, $finance);
        $this->assertSame($this->director->id, $this->pendingTask($instance)->assignee_id);

        // A rejection in one branch ends the request and closes the other branch.
        $second = $this->engine()->start($definition, $this->initiator, []);
        $this->engine()->decide($this->pendingTask($second, $finance), $finance, 'reject', 'Over budget');
        $second->refresh();
        $this->assertSame('rejected', $second->status);
        $this->assertSame('cancelled', $second->tasks()->where('assignee_id', $tech->id)->value('status'));
    }

    public function test_committee_majority_decides_as_soon_as_the_outcome_is_certain(): void
    {
        [$a, $b, $c] = [$this->person('A'), $this->person('B'), $this->person('C')];
        $this->committee('PC', [[$a, 1], [$b, 1], [$c, 1]]);
        $definition = $this->workflow([
            ['start', 'start'],
            ['pc', 'committee', 'Purchase committee', ['committee' => 'PC', 'rule' => 'majority']],
            ['ok', 'end', 'Approved'],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'pc'], ['pc', 'ok', 'approve'], ['pc', 'no', 'reject']]);

        $instance = $this->engine()->start($definition, $this->initiator, []);
        $this->assertSame(3, $instance->tasks()->where('status', 'pending')->where('kind', 'committee_vote')->count());

        $this->approve($instance, $a);
        $this->assertSame('in_progress', $instance->fresh()->status, 'one of three is not a majority');
        $this->approve($instance, $b);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);
        $this->assertSame('superseded', $instance->tasks()->where('assignee_id', $c->id)->value('status'), 'the last vote cannot change the result');
    }

    public function test_all_mode_needs_every_assignee(): void
    {
        $other = $this->person('Second HoD', 'faculty', $this->cse);
        $this->holds('HOD', $other, $this->cse, 'department');
        $definition = $this->simpleApproval(['mode' => 'all']);

        $instance = $this->engine()->start($definition, $this->initiator, []);
        $this->approve($instance, $this->hod);
        $this->assertSame('in_progress', $instance->fresh()->status);
        $this->approve($instance, $other);
        $this->assertSame('completed', $instance->fresh()->status);
    }

    public function test_delegation_routes_new_work_and_lets_the_delegate_act_on_existing_work(): void
    {
        $deputy = $this->person('Deputy', 'faculty', $this->cse);
        $definition = $this->simpleApproval();

        // Existing task, then the HoD goes on leave.
        $before = $this->engine()->start($definition, $this->initiator, []);
        app(DelegationService::class)->create($this->hod, ['delegate_id' => $deputy->id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addWeek(), 'reason' => 'On leave'], $this->hod);

        $this->engine()->decide($this->pendingTask($before), $deputy, 'approve', 'For HoD');
        $action = WorkflowAction::query()->where('workflow_instance_id', $before->id)->where('action', 'approved')->sole();
        $this->assertSame($deputy->id, $action->actor_id);
        $this->assertSame($this->hod->id, $action->on_behalf_of_id, 'the record shows on whose behalf');

        // New work goes straight to the delegate.
        $after = $this->engine()->start($definition, $this->initiator, []);
        $task = $this->pendingTask($after);
        $this->assertSame($deputy->id, $task->assignee_id);
        $this->assertSame($this->hod->id, $task->principal_id);
    }

    public function test_delegation_does_not_bypass_separation_of_duties(): void
    {
        $definition = $this->simpleApproval();
        // The HoD delegates to the very person whose request is pending.
        app(DelegationService::class)->create($this->hod, ['delegate_id' => $this->initiator->id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addWeek(), 'reason' => 'Leave'], $this->hod);

        $instance = $this->engine()->start($definition, $this->initiator, []);
        $task = $instance->tasks()->where('status', 'pending')->first();
        $this->assertSame($this->hod->id, $task->assignee_id, 'the delegation does not apply to the delegate\'s own request');

        $this->expectException(WorkflowException::class);
        $this->engine()->decide($task, $this->initiator, 'approve');
    }

    public function test_nobody_approves_their_own_request(): void
    {
        $supervisor = tap($this->person('Supervisor'))->assignRole('workflow_supervisor');
        $definition = $this->simpleApproval();

        // The HoD raises a request: the HoD step resolves to themselves.
        $instance = $this->engine()->start($definition, $this->hod, []);
        $task = $this->pendingTask($instance);
        $this->assertSame($supervisor->id, $task->assignee_id, 'no fallback configured: supervisors receive it');
        $this->assertDatabaseHas('audit_events', ['action' => 'workflow.assignment_unresolved', 'subject_id' => $instance->id]);
    }

    public function test_fallback_assignee_is_used_when_a_step_cannot_be_resolved(): void
    {
        $definition = $this->simpleApproval(settings: ['fallback' => ['type' => 'authority', 'authority' => 'REGISTRAR']]);
        $outsider = $this->person('Outsider', 'staff', $this->ece); // ECE has no HoD
        $instance = $this->engine()->start($definition, $outsider, []);

        $this->assertSame($this->registrar->id, $this->pendingTask($instance)->assignee_id);
    }

    public function test_a_task_cannot_be_decided_twice(): void
    {
        $instance = $this->engine()->start($this->simpleApproval(), $this->initiator, []);
        $task = $this->pendingTask($instance);
        $this->engine()->decide($task, $this->hod, 'approve');

        try {
            $this->engine()->decide($task, $this->hod, 'reject', 'changed my mind');
            $this->fail('second decision must be refused');
        } catch (WorkflowException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(1, WorkflowAction::query()->where('workflow_task_id', $task->id)->where('is_decision', true)->count());

        // And the database refuses a second decision row outright.
        $this->expectException(QueryException::class);
        DB::table('workflow_actions')->insert(['workflow_instance_id' => $instance->id, 'workflow_task_id' => $task->id, 'action' => 'rejected', 'is_decision' => true, 'created_at' => now()]);
    }

    public function test_only_the_assignee_can_decide(): void
    {
        $instance = $this->engine()->start($this->simpleApproval(), $this->initiator, []);

        $this->expectException(WorkflowException::class);
        $this->engine()->decide($this->pendingTask($instance), $this->registrar, 'approve');
    }

    public function test_running_requests_keep_their_version_when_the_workflow_changes(): void
    {
        $definition = $this->simpleApproval();
        $old = $this->engine()->start($definition, $this->initiator, []);

        // v2 adds a Registrar step after the HoD.
        $definition = $this->workflow([
            ['start', 'start'],
            ['hod', 'approval', 'HoD', ['assignees' => [['type' => 'authority', 'authority' => 'HOD']]]],
            ['reg', 'approval', 'Registrar', ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]]],
            ['ok', 'end', 'Approved'],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'hod'], ['hod', 'reg', 'approve'], ['hod', 'no', 'reject'], ['reg', 'ok', 'approve'], ['reg', 'no', 'reject']]);
        $new = $this->engine()->start($definition, $this->initiator, []);

        $this->approve($old, $this->hod);
        $this->assertSame('completed', $old->fresh()->status, 'v1 has no Registrar step');
        $this->approve($new, $this->hod);
        $this->assertSame($this->registrar->id, $this->pendingTask($new)->assignee_id);
        $this->assertSame([1, 2], [$old->version()->value('version'), $new->version()->value('version')]);
    }

    public function test_published_versions_are_immutable(): void
    {
        $definition = $this->simpleApproval();
        $node = WorkflowNode::query()->where('workflow_version_id', $definition->currentVersion()->value('id'))->where('key', 'hod')->sole();

        $this->expectException(LogicException::class);
        $node->forceFill(['config' => ['assignees' => [['type' => 'initiator']]]])->save();
    }

    public function test_hold_pauses_the_deadline_and_blocks_decisions(): void
    {
        $instance = $this->engine()->start($this->simpleApproval(['sla' => ['duration' => 2, 'unit' => 'days'], 'actions' => ['approve', 'reject', 'return', 'hold']]), $this->initiator, []);
        $due = $this->pendingTask($instance)->due_at;

        $this->engine()->hold($instance, $this->hod, 'Awaiting budget head');
        try {
            $this->approve($instance, $this->hod);
            $this->fail('held requests cannot be decided');
        } catch (WorkflowException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->travel(3)->hours();
        $this->engine()->resume($instance, $this->hod);
        $this->assertEqualsWithDelta($due->addHours(3)->timestamp, $this->pendingTask($instance)->due_at->timestamp, 5);
    }

    public function test_initiator_can_cancel_but_others_cannot(): void
    {
        $instance = $this->engine()->start($this->simpleApproval(), $this->initiator, []);

        try {
            $this->engine()->cancel($instance, $this->registrar, 'not mine');
            $this->fail();
        } catch (WorkflowException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->engine()->cancel($instance, $this->initiator, 'No longer needed');
        $instance->refresh();
        $this->assertSame('cancelled', $instance->status);
        $this->assertSame('cancelled', $instance->tasks()->value('status'));
    }

    public function test_invalid_state_transitions_are_refused(): void
    {
        $instance = $this->engine()->start($this->simpleApproval(), $this->initiator, []);
        $this->approve($instance, $this->hod);

        $this->expectException(WorkflowException::class);
        $this->engine()->cancel($instance->fresh(), $this->initiator, 'too late');
    }

    public function test_forward_moves_the_task_and_keeps_the_record(): void
    {
        $colleague = $this->person('Colleague', 'faculty', $this->cse);
        $instance = $this->engine()->start($this->simpleApproval(['actions' => ['approve', 'reject', 'forward']]), $this->initiator, []);

        $this->engine()->forward($this->pendingTask($instance), $this->hod, $colleague, 'Please examine');
        $this->assertSame($colleague->id, $this->pendingTask($instance)->assignee_id);
        $this->assertSame('forwarded', WorkflowTask::query()->where('assignee_id', $this->hod->id)->value('outcome'));

        $this->expectException(WorkflowException::class); // not allowed back to the initiator
        $this->engine()->forward($this->pendingTask($instance), $colleague, $this->initiator, 'x');
    }

    public function test_drafts_have_no_number_until_submitted(): void
    {
        $formId = $this->form([['key' => 'purpose', 'type' => 'text', 'label' => 'Purpose', 'required' => true]]);
        $definition = $this->simpleApproval([], $formId);

        $draft = $this->engine()->start($definition, $this->initiator, ['purpose' => '', 'junk' => 'dropped'], submit: false);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->reference);
        $this->assertArrayNotHasKey('junk', $draft->data);

        $this->engine()->submit($draft, $this->initiator, ['purpose' => 'Workshop']);
        $this->assertNotNull($draft->fresh()->reference);
    }

    public function test_ineligible_initiators_cannot_start(): void
    {
        $definition = $this->simpleApproval(settings: ['initiators' => ['categories' => ['student']]]);

        $this->expectException(WorkflowException::class);
        $this->engine()->start($definition, $this->initiator, []);
    }
}
