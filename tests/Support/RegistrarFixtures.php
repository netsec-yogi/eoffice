<?php

namespace Tests\Support;

use App\Modules\Calendar\Models\BusinessCalendar;
use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Services\LeaveService;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Database\Seeders\RegistrarWorkflowSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Organisation + the Registrar example workflows, on a fixed working calendar. */
trait RegistrarFixtures
{
    use BuildsWorkflows;

    protected User $financeClerk;

    protected User $financeOfficer;

    protected User $rtiClerk;

    protected User $faa;

    protected User $establishment;

    protected function setUpRegistrar(): void
    {
        Mail::fake();
        Storage::fake('private');
        $this->setUpOrganisation();
        $this->designer->assignRole('system_admin');
        $this->financeClerk = tap($this->person('Finance clerk'))->assignRole('finance_staff');
        $this->financeOfficer = $this->person('Finance Officer');
        $this->rtiClerk = tap($this->person('RTI clerk'))->assignRole('rti_cell');
        $this->faa = $this->person('Appellate authority');
        $this->establishment = tap($this->person('Establishment'))->assignRole('registrar_staff');
        $this->holds('FINANCE_OFFICER', $this->financeOfficer);
        $this->holds('RTI_FAA', $this->faa);

        $cal = BusinessCalendar::create(['code' => 'T', 'name' => 'Test', 'working_days' => [1, 2, 3, 4, 5], 'day_starts_at' => '09:00', 'day_ends_at' => '17:30', 'is_default' => true]);
        $cal->holidays()->create(['date' => '2026-11-04', 'name' => 'Test holiday']); // a Wednesday
        $this->travelTo('2026-10-20 10:00'); // Tuesday

        $this->seed(RegistrarWorkflowSeeder::class);
    }

    protected function credit(User $user, string $code, float $days): void
    {
        app(LeaveService::class)->post($user, LeaveType::query()->where('code', $code)->firstOrFail(), 'credit', $days, 'Opening balance', $this->establishment);
    }

    protected function start(string $key, User $who, array $data): WorkflowInstance
    {
        return app(WorkflowEngine::class)->start(WorkflowDefinition::query()->where('key', $key)->sole(), $who, $data);
    }

    protected function act(WorkflowInstance $i, User $who, string $action = 'approve', array $data = [], ?string $remarks = 'ok'): WorkflowInstance
    {
        $task = $i->tasks()->where('status', 'pending')->where('assignee_id', $who->id)->firstOrFail();

        return app(WorkflowEngine::class)->decide($task, $who, $action, $remarks, $data);
    }
}
