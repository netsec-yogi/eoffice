<?php

namespace Tests\Feature\Workflow;

use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Workflow\Automation\ActionRegistry;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Jobs\RunAutomation;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTaskEvent;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsWorkflows;
use Tests\TestCase;

/** WF-014, WF-015, WF-016 */
class SlaAutomationTest extends TestCase
{
    use BuildsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();
    }

    public function test_reminders_and_escalations_fire_once_per_level(): void
    {
        $definition = $this->simpleApproval(['sla' => ['duration' => 2, 'unit' => 'days', 'escalations' => [
            ['after' => 1, 'unit' => 'days', 'to' => ['type' => 'authority', 'authority' => 'REGISTRAR'], 'action' => 'notify'],
            ['after' => 3, 'unit' => 'days', 'to' => ['type' => 'authority', 'authority' => 'DIRECTOR'], 'action' => 'reassign'],
        ]]]);
        $instance = $this->engine()->start($definition, $this->initiator, []);
        $task = $this->pendingTask($instance);

        $this->travel(36)->hours(); // 75% of the time used
        $this->artisan('workflow:sla-scan')->assertSuccessful();
        $this->assertSame(1, WorkflowTaskEvent::query()->where('kind', 'reminder')->count());

        $this->travel(2)->days(); // 1 day past due: first escalation
        $this->artisan('workflow:sla-scan');
        $this->artisan('workflow:sla-scan'); // a second run changes nothing
        $this->assertSame(1, $task->fresh()->escalation_level);
        $this->assertSame(1, NotificationMessage::query()->where('user_id', $this->registrar->id)->where('template_key', 'workflow.task_escalated')->where('channel', 'in_app')->count());
        $this->assertSame(2, WorkflowTaskEvent::query()->where('kind', 'reminder')->count(), 'pre-deadline and overdue reminders');

        $this->travel(3)->days(); // level 2 reassigns to the Director
        $this->artisan('workflow:sla-scan');
        $this->assertSame('superseded', $task->fresh()->status);
        $this->assertSame($this->director->id, $this->pendingTask($instance)->assignee_id);
        $this->assertDatabaseHas('workflow_actions', ['workflow_instance_id' => $instance->id, 'action' => 'escalated']);
    }

    public function test_held_requests_are_not_escalated(): void
    {
        $definition = $this->simpleApproval(['actions' => ['approve', 'reject', 'hold'], 'sla' => ['duration' => 1, 'unit' => 'hours', 'escalations' => [
            ['after' => 1, 'unit' => 'hours', 'to' => ['type' => 'authority', 'authority' => 'REGISTRAR']],
        ]]]);
        $instance = $this->engine()->start($definition, $this->initiator, []);
        $this->engine()->hold($instance, $this->hod, 'waiting');

        $this->travel(5)->hours();
        $this->artisan('workflow:sla-scan');
        $this->assertSame(0, WorkflowTaskEvent::query()->count());
    }

    public function test_automation_success_continues_and_stores_variables(): void
    {
        $definition = $this->workflow([
            ['start', 'start'],
            ['auto', 'automation', 'Compute', ['action' => 'core.set_variables', 'settings' => ['values' => ['greeting' => 'Hello {{ name }}']]]],
            ['ok', 'end', 'Done'],
        ], [['start', 'auto'], ['auto', 'ok', 'success']], $this->form([['key' => 'name', 'type' => 'text', 'label' => 'Name']]));

        $instance = $this->engine()->start($definition, $this->initiator, ['name' => 'World']);

        $instance->refresh();
        $this->assertSame('completed', $instance->status, 'sync queue in tests runs the job after commit');
        $this->assertSame('Hello World', $instance->variables['greeting']);
        $this->assertSame('succeeded', IntegrationJob::query()->value('status'));
    }

    public function test_unavailable_integrations_become_manual_tasks_not_fake_successes(): void
    {
        config(['workflow.actions' => config('workflow.actions') + ['test.provision' => BlockedAction::class]]);
        $definition = $this->workflow([
            ['start', 'start'],
            ['auto', 'automation', 'Create mailbox', ['action' => 'test.provision', 'on_blocked' => ['type' => 'authority', 'authority' => 'REGISTRAR']]],
            ['ok', 'end', 'Done'],
            ['no', 'end', 'Failed', ['outcome' => 'rejected']],
        ], [['start', 'auto'], ['auto', 'ok', 'success'], ['auto', 'no', 'failure']]);

        $instance = $this->engine()->start($definition, $this->initiator, []);

        $instance->refresh();
        $this->assertSame('in_progress', $instance->status);
        $this->assertSame('blocked', IntegrationJob::query()->value('status'));
        $task = $this->pendingTask($instance);
        $this->assertSame('manual', $task->kind);
        $this->assertSame($this->registrar->id, $task->assignee_id);

        $this->engine()->decide($task, $this->registrar, 'complete', 'Created by hand');
        $this->assertSame('completed', $instance->fresh()->status);
    }

    public function test_failures_take_the_failure_path(): void
    {
        config(['workflow.actions' => config('workflow.actions') + ['test.fail' => FailingAction::class]]);
        $definition = $this->workflow([
            ['start', 'start'],
            ['auto', 'automation', 'Call system', ['action' => 'test.fail']],
            ['ok', 'end', 'Done'],
            ['no', 'end', 'Failed', ['outcome' => 'rejected']],
        ], [['start', 'auto'], ['auto', 'ok', 'success'], ['auto', 'no', 'failure']]);

        $instance = $this->engine()->start($definition, $this->initiator, []);
        $this->assertSame('rejected', $instance->fresh()->status);
        $this->assertSame('Quota exceeded', IntegrationJob::query()->value('last_error'));
    }

    public function test_finished_jobs_are_never_run_twice(): void
    {
        config(['workflow.actions' => config('workflow.actions') + ['test.count' => CountingAction::class]]);
        CountingAction::$runs = 0;
        $definition = $this->workflow([
            ['start', 'start'], ['auto', 'automation', 'Count', ['action' => 'test.count']], ['ok', 'end', 'Done'],
        ], [['start', 'auto'], ['auto', 'ok', 'success']]);
        $this->engine()->start($definition, $this->initiator, []);

        (new RunAutomation(IntegrationJob::query()->value('id')))->handle(app(ActionRegistry::class), $this->engine());
        $this->assertSame(1, CountingAction::$runs);
    }

    public function test_timers_continue_after_the_wait(): void
    {
        $definition = $this->workflow([
            ['start', 'start'], ['wait', 'timer', 'Cooling-off', ['duration' => 2, 'unit' => 'hours']], ['ok', 'end', 'Done'],
        ], [['start', 'wait'], ['wait', 'ok']]);
        $instance = $this->engine()->start($definition, $this->initiator, []);

        $this->artisan('workflow:tick');
        $this->assertSame('in_progress', $instance->fresh()->status);
        $this->travel(3)->hours();
        $this->artisan('workflow:tick');
        $this->assertSame('completed', $instance->fresh()->status);
    }
}

class BlockedAction implements AutomationAction
{
    public function label(): string
    {
        return 'Blocked';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        return AutomationResult::blocked('Mail platform API is not configured.');
    }
}

class FailingAction extends BlockedAction
{
    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        return AutomationResult::failure('Quota exceeded');
    }
}

class CountingAction extends BlockedAction
{
    public static int $runs = 0;

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        self::$runs++;

        return AutomationResult::success();
    }
}
