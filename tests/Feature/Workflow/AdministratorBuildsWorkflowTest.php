<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Graph\WorkflowDesigner;
use App\Modules\Workflow\Models\FormTemplate;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Phase 2 exit criterion: an authorised administrator creates, publishes and
 * executes a configurable multi-stage workflow without modifying source
 * code. Everything below goes through HTTP, as the UI does.
 */
class AdministratorBuildsWorkflowTest extends TestCase
{
    use BuildsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();
    }

    public function test_administrator_builds_publishes_and_runs_a_workflow_over_http(): void
    {
        $admin = $this->designer;

        // 1. A form with a conditional field and an approver-only field.
        $this->actingAs($admin)->post('/admin/forms', ['key' => 'tour', 'name' => 'Tour request'])->assertRedirect();
        $template = FormTemplate::query()->where('key', 'tour')->sole();
        $formVersion = $template->versions()->first();
        $this->put("/admin/form-versions/{$formVersion->id}", ['schema' => ['sections' => [['key' => 'trip', 'title' => 'Trip', 'fields' => [
            ['key' => 'purpose', 'type' => 'text', 'label' => 'Purpose', 'required' => true],
            ['key' => 'mode', 'type' => 'radio', 'label' => 'Travel', 'required' => true, 'options' => [['value' => 'domestic', 'label' => 'Domestic'], ['value' => 'international', 'label' => 'International']]],
            ['key' => 'country', 'type' => 'text', 'label' => 'Country', 'required' => true, 'visible_if' => ['field' => 'mode', 'op' => 'eq', 'value' => 'international']],
            ['key' => 'estimate', 'type' => 'decimal', 'label' => 'Estimate', 'required' => true],
            ['key' => 'sanctioned', 'type' => 'decimal', 'label' => 'Sanctioned amount', 'editable_at' => ['registrar']],
        ]]]]])->assertSessionHasNoErrors();
        $this->post("/admin/form-versions/{$formVersion->id}/publish")->assertSessionHas('success');

        // 2. A workflow: HoD -> (international ? Director) -> Registrar.
        $this->post('/admin/workflows', ['key' => 'tour', 'name' => 'Tour approval', 'category' => 'travel'])->assertRedirect();
        $definition = WorkflowDefinition::query()->where('key', 'tour')->sole();
        $draft = $definition->versions()->first();
        $graph = [
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Submitted', 'x' => 0, 'y' => 0],
                ['key' => 'hod', 'type' => 'approval', 'name' => 'HoD recommendation', 'config' => ['assignees' => [['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']], 'sla' => ['duration' => 2, 'unit' => 'working_days']], 'x' => 0, 'y' => 120],
                ['key' => 'route', 'type' => 'decision', 'name' => 'International?', 'x' => 0, 'y' => 240],
                ['key' => 'director', 'type' => 'approval', 'name' => 'Director', 'config' => ['assignees' => [['type' => 'authority', 'authority' => 'DIRECTOR']]], 'x' => 200, 'y' => 360],
                ['key' => 'registrar', 'type' => 'approval', 'name' => 'Registrar sanction', 'config' => ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]], 'x' => 0, 'y' => 480],
                ['key' => 'approved', 'type' => 'end', 'name' => 'Sanctioned', 'config' => ['outcome' => 'approved'], 'x' => 0, 'y' => 600],
                ['key' => 'rejected', 'type' => 'end', 'name' => 'Not sanctioned', 'config' => ['outcome' => 'rejected'], 'x' => 300, 'y' => 600],
            ],
            'edges' => [
                ['from_node' => 'start', 'to_node' => 'hod', 'outcome' => 'default'],
                ['from_node' => 'hod', 'to_node' => 'route', 'outcome' => 'approve'],
                ['from_node' => 'hod', 'to_node' => 'rejected', 'outcome' => 'reject'],
                ['from_node' => 'route', 'to_node' => 'director', 'outcome' => 'default', 'condition' => ['field' => 'mode', 'op' => 'eq', 'value' => 'international'], 'priority' => 10],
                ['from_node' => 'route', 'to_node' => 'registrar', 'outcome' => 'default', 'priority' => 20],
                ['from_node' => 'director', 'to_node' => 'registrar', 'outcome' => 'approve'],
                ['from_node' => 'director', 'to_node' => 'rejected', 'outcome' => 'reject'],
                ['from_node' => 'registrar', 'to_node' => 'approved', 'outcome' => 'approve'],
                ['from_node' => 'registrar', 'to_node' => 'rejected', 'outcome' => 'reject'],
            ],
            'settings' => ['initiators' => ['categories' => ['faculty', 'staff']], 'title_template' => 'Tour: {{ purpose }}', 'fallback' => ['type' => 'authority', 'authority' => 'REGISTRAR']],
            'form_version_id' => $formVersion->id,
        ];
        $this->put("/admin/workflow-versions/{$draft->id}", $graph)->assertSessionHasNoErrors();
        $this->getJson("/admin/workflow-versions/{$draft->id}/check")->assertOk()->assertJsonPath('errors', []);
        $this->post("/admin/workflow-versions/{$draft->id}/publish", ['note' => 'First version'])->assertSessionHas('success');
        $this->get("/admin/workflows/{$definition->id}")->assertOk();

        // 3. A faculty member finds it and submits an international trip.
        $this->actingAs($this->initiator)->get('/requests/new')->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflows.0.key', 'tour'));
        $this->post('/requests/new/tour', ['data' => ['purpose' => 'IEEE conference', 'mode' => 'international', 'estimate' => 150000]])
            ->assertSessionHasErrors('country');
        $this->post('/requests/new/tour', ['data' => ['purpose' => 'IEEE conference', 'mode' => 'international', 'country' => 'Japan', 'estimate' => 150000, 'sanctioned' => 999999]])
            ->assertRedirect();
        $instance = WorkflowInstance::query()->sole();
        $this->assertSame('Tour: IEEE conference', $instance->title);
        $this->assertNull($instance->data['sanctioned'], 'approver-only field ignored at submission');

        // 4. Outsiders cannot see it; the HoD can, and decides.
        $this->actingAs($this->registrar)->get("/requests/{$instance->id}")->assertForbidden();
        $task = $instance->tasks()->where('status', 'pending')->sole();
        $this->actingAs($this->registrar)->post("/tasks/{$task->id}/decide", ['action' => 'approve'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->hod)->get('/tasks')->assertOk()->assertInertia(fn ($page) => $page->where('tasks.total', 1));
        $this->actingAs($this->hod)->get("/requests/{$instance->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('instance.my_tasks.0.id', $task->id));
        $this->post("/tasks/{$task->id}/decide", ['action' => 'approve', 'remarks' => 'Recommended'])->assertRedirect('/tasks');

        // 5. International -> Director, who returns it; the initiator answers.
        $task = $instance->tasks()->where('status', 'pending')->sole();
        $this->assertSame($this->director->id, $task->assignee_id);
        $this->actingAs($this->director)->post("/tasks/{$task->id}/decide", ['action' => 'return', 'remarks' => 'Attach the acceptance letter', 'return_kind' => 'documents']);
        $this->assertSame('returned', $instance->fresh()->status);
        $this->actingAs($this->initiator)->get("/requests/{$instance->id}/edit")->assertOk();
        $this->put("/requests/{$instance->id}", ['data' => ['purpose' => 'IEEE conference', 'mode' => 'international', 'country' => 'Japan', 'estimate' => 140000], 'remarks' => 'Letter attached'])->assertRedirect();

        // 6. Director approves, Registrar sanctions a reduced amount.
        $task = $instance->tasks()->where('status', 'pending')->sole();
        $this->actingAs($this->director)->post("/tasks/{$task->id}/decide", ['action' => 'approve']);
        $task = $instance->tasks()->where('status', 'pending')->sole();
        $this->assertSame($this->registrar->id, $task->assignee_id);
        $this->actingAs($this->registrar)->post("/tasks/{$task->id}/decide", ['action' => 'approve', 'data' => ['sanctioned' => 120000, 'estimate' => 1]]);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);
        $this->assertEquals(120000, $instance->data['sanctioned']); // JSONB stores 120000.0 as 120000
        $this->assertEquals(140000, $instance->data['estimate'], 'the approver could only change their own field');
        $this->assertSame(2, $instance->revision);

        // 7. The initiator sees the full history.
        $this->actingAs($this->initiator)->get("/requests/{$instance->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('instance.status', 'completed')
                ->where('instance.timeline', fn ($t) => collect($t)->pluck('action')->all() === [
                    'submitted', 'assigned', 'approved', 'assigned', 'returned', 'resubmitted', 'assigned', 'approved', 'assigned', 'approved', 'completed',
                ]));
    }

    public function test_designer_pages_are_permission_protected(): void
    {
        $employee = $this->person('Someone');
        $this->actingAs($employee)->get('/admin/workflows')->assertForbidden();
        $this->actingAs($employee)->post('/admin/workflows', ['key' => 'x', 'name' => 'X', 'category' => 'general'])->assertForbidden();

        $designerOnly = tap($this->person('Designer only'))->assignRole('workflow_designer');
        $definition = $this->simpleApproval();
        $draft = app(WorkflowDesigner::class)->draft($definition, $this->designer);
        $this->actingAs($designerOnly)->post("/admin/workflow-versions/{$draft->id}/publish", ['note' => 'x'])->assertForbidden();
    }

    public function test_monitor_respects_department_scope(): void
    {
        $definition = $this->simpleApproval(settings: ['fallback' => ['type' => 'authority', 'authority' => 'REGISTRAR']]);
        $this->engine()->start($definition, $this->initiator, []);
        $eceUser = $this->person('ECE person', 'staff', $this->ece);
        $this->engine()->start($definition, $eceUser, []);

        $deptMonitor = tap($this->person('CSE monitor', 'staff', $this->cse))->givePermissionTo('workflows.monitor.department');
        $this->actingAs($deptMonitor)->get('/monitor')->assertOk()->assertInertia(fn ($page) => $page->where('instances.total', 1));

        $global = tap($this->person('Global'))->assignRole('auditor');
        $this->actingAs($global)->get('/monitor')->assertOk()->assertInertia(fn ($page) => $page->where('instances.total', 2));

        $this->actingAs($this->person('Nobody'))->get('/monitor')->assertForbidden();
    }

    public function test_dashboard_and_inbox_counts_come_from_the_database(): void
    {
        $this->engine()->start($this->simpleApproval(), $this->initiator, []);

        $this->actingAs($this->hod)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.counts.pending', 1)->where('counts.tasks', 1));
        $this->actingAs($this->initiator)->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.counts.open', 1)->where('stats.counts.pending', 0));
    }
}
