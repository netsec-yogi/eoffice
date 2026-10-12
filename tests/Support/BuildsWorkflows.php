<?php

namespace Tests\Support;

use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Forms\FormService;
use App\Modules\Workflow\Graph\WorkflowDesigner;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;

/**
 * A small synthetic institute and helpers to publish workflows through the
 * same services the designer uses.
 */
trait BuildsWorkflows
{
    protected Department $cse;

    protected Department $ece;

    protected User $initiator;

    protected User $hod;

    protected User $registrar;

    protected User $director;

    protected User $designer;

    protected function setUpOrganisation(): void
    {
        $this->cse = Department::create(['code' => 'CSE', 'name' => 'Computer Science', 'type' => 'academic']);
        $this->ece = Department::create(['code' => 'ECE', 'name' => 'Electronics', 'type' => 'academic']);

        $this->initiator = $this->person('Initiator', 'faculty', $this->cse);
        $this->hod = $this->person('Hod', 'faculty', $this->cse);
        $this->registrar = $this->person('Registrar', 'staff');
        $this->director = $this->person('Director', 'staff');
        $this->designer = tap($this->person('Designer', 'staff'))->assignRole('workflow_publisher');

        $this->holds('HOD', $this->hod, $this->cse, 'department');
        $this->holds('REGISTRAR', $this->registrar);
        $this->holds('DIRECTOR', $this->director);
        $this->initiator->forceFill(['reports_to_id' => $this->hod->id])->save();
    }

    protected function person(string $name, string $category = 'staff', ?Department $department = null): User
    {
        return tap(User::factory()->create(['name' => $name, 'category' => $category, 'department_id' => $department?->id]))
            ->assignRole($category === 'student' ? 'student' : 'employee')->fresh();
    }

    protected function holds(string $code, User $user, ?Department $department = null, string $scope = 'institute'): void
    {
        $authority = Authority::query()->firstOrCreate(['code' => $code], ['name' => ucfirst(strtolower($code)), 'scope' => $scope]);
        $authority->assignments()->create(['user_id' => $user->id, 'department_id' => $department?->id, 'starts_on' => now()->subYear()->toDateString()]);
    }

    protected function committee(string $code, array $members): Committee
    {
        $committee = Committee::create(['code' => $code, 'name' => $code]);
        foreach ($members as [$user, $weight]) {
            $committee->members()->create(['user_id' => $user->id, 'role' => 'member', 'voting_weight' => $weight, 'starts_on' => now()->subMonth()->toDateString()]);
        }

        return $committee;
    }

    /** A published form version for $fields (one section). */
    protected function form(array $fields, string $key = 'test_form'): int
    {
        $forms = app(FormService::class);
        $template = $forms->create(['key' => $key, 'name' => 'Test form'], $this->designer);
        $version = $template->versions()->first();
        $forms->saveDraft($version, ['sections' => [['key' => 'main', 'title' => 'Main', 'fields' => $fields]]], $this->designer);

        return $forms->publish($version, $this->designer)->id;
    }

    /**
     * Publish a workflow. Nodes: [key, type, name, config]; edges: [from, to, outcome, condition?].
     */
    protected function workflow(array $nodes, array $edges, ?int $formVersionId = null, array $settings = [], string $key = 'test', string $category = 'general'): WorkflowDefinition
    {
        $designer = app(WorkflowDesigner::class);
        $definition = WorkflowDefinition::query()->where('key', $key)->first()
            ?? $designer->create(['key' => $key, 'name' => 'Test workflow', 'category' => $category, 'numbering_pattern' => '{KEY}/{YYYY}/{SEQ:4}'], $this->designer);
        $draft = $designer->draft($definition, $this->designer);

        $designer->saveDraft($draft, [
            'nodes' => array_map(fn ($n) => ['key' => $n[0], 'type' => $n[1], 'name' => $n[2] ?? $n[0], 'config' => $n[3] ?? []], $nodes),
            'edges' => array_map(fn ($e, $i) => ['key' => "e{$i}", 'from_node' => $e[0], 'to_node' => $e[1], 'outcome' => $e[2] ?? 'default', 'condition' => $e[3] ?? null, 'priority' => $e[4] ?? 100], $edges, array_keys($edges)),
            'settings' => $settings + ['initiators' => []],
            'form_version_id' => $formVersionId,
        ], $this->designer);
        $designer->publish($draft, $this->designer, 'test');

        return $definition->refresh();
    }

    /** Start -> one approval step -> approved / rejected ends. */
    protected function simpleApproval(array $stepConfig = [], ?int $formVersionId = null, array $settings = []): WorkflowDefinition
    {
        return $this->workflow([
            ['start', 'start', 'Submitted'],
            ['hod', 'approval', 'HoD approval', $stepConfig + ['assignees' => [['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]]],
            ['ok', 'end', 'Approved', ['outcome' => 'approved']],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'hod'], ['hod', 'ok', 'approve'], ['hod', 'no', 'reject']], $formVersionId, $settings);
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    protected function pendingTask(WorkflowInstance $instance, ?User $assignee = null): WorkflowTask
    {
        return $instance->tasks()->where('status', 'pending')->when($assignee, fn ($q) => $q->where('assignee_id', $assignee->id))->firstOrFail();
    }

    protected function approve(WorkflowInstance $instance, User $by, ?User $assignee = null): WorkflowInstance
    {
        return $this->engine()->decide($this->pendingTask($instance, $assignee ?? $by), $by, 'approve', 'ok');
    }
}
