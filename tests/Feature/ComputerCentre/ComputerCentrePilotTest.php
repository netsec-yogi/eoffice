<?php

namespace Tests\Feature\ComputerCentre;

use App\Modules\ComputerCentre\Actions\Deprovision;
use App\Modules\ComputerCentre\Integrations\MailboxProvisioner;
use App\Modules\ComputerCentre\Integrations\NetworkAccessProvisioner;
use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\ComputerCentre\Models\LicenceAllocation;
use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\ComputerCentre\Services\AccountLedger;
use App\Modules\ComputerCentre\Services\AssetService;
use App\Modules\ComputerCentre\Services\LicenceService;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use Database\Seeders\ComputerCentreWorkflowSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsWorkflows;
use Tests\Support\FakeMailbox;
use Tests\Support\FakeNetwork;
use Tests\TestCase;

/** Phase 3: the Computer Centre runs its principal workflows on the common engine. */
class ComputerCentrePilotTest extends TestCase
{
    use BuildsWorkflows;

    private User $operator;

    private User $ccHead;

    private User $finance;

    private User $student;

    private FakeNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();
        $this->designer->assignRole('system_admin');
        $this->operator = tap($this->person('CC Operator', 'staff'))->assignRole('cc_operator');
        $this->ccHead = $this->person('CC Head', 'staff');
        $this->finance = $this->person('Finance Officer', 'staff');
        $this->student = $this->person('Student', 'student', $this->cse);
        $this->holds('CC_HEAD', $this->ccHead);
        $this->holds('FINANCE_OFFICER', $this->finance);

        $this->network = new FakeNetwork;
        $this->app->instance(NetworkAccessProvisioner::class, $this->network);
        $this->seed(ComputerCentreWorkflowSeeder::class);
    }

    private function start(string $key, User $who, array $data): WorkflowInstance
    {
        return app(WorkflowEngine::class)->start(WorkflowDefinition::query()->where('key', $key)->sole(), $who, $data);
    }

    private function act(WorkflowInstance $i, User $who, string $action = 'approve', array $data = [], ?string $remarks = 'ok'): WorkflowInstance
    {
        $task = $i->tasks()->where('status', 'pending')->where('assignee_id', $who->id)->firstOrFail();

        return app(WorkflowEngine::class)->decide($task, $who, $action, $remarks, $data);
    }

    public function test_all_pilot_workflows_are_published_configuration(): void
    {
        $this->assertEqualsCanonicalizing(
            ['it-email', 'it-wifi', 'it-vpn', 'it-vm', 'it-asset', 'it-software', 'it-deprovision', 'no-dues'],
            WorkflowDefinition::query()->whereHas('currentVersion')->pluck('key')->all(),
        );
    }

    public function test_email_without_a_mail_platform_becomes_a_manual_task_and_is_recorded(): void
    {
        $i = $this->start('it-email', $this->initiator, ['purpose' => 'New joiner', 'alternate_email' => 'me@example.com', 'aup' => true]);
        $this->act($i, $this->hod);           // recommendation (reporting officer)
        $this->act($i, $this->operator);      // verification

        $job = IntegrationJob::query()->where('action', 'cc.provision_email')->sole();
        $this->assertSame('blocked', $job->status, 'no mail adapter: never reported as created');
        $manual = WorkflowTask::query()->where('kind', 'manual')->sole();
        $this->assertSame($this->operator->id, $manual->assignee_id);

        $this->act($i, $this->operator, 'complete', remarks: 'Created in the mail console');
        $this->assertSame('completed', $i->fresh()->status);
        $account = ItAccount::query()->sole();
        $this->assertSame(['email', 'manual', 'active'], [$account->kind, $account->provisioned_by, $account->status]);
    }

    public function test_email_is_created_automatically_when_an_adapter_is_configured(): void
    {
        $this->app->instance(MailboxProvisioner::class, $mail = new FakeMailbox);
        $i = $this->start('it-email', $this->initiator, ['purpose' => 'x', 'preferred_address' => 'initiator.cse', 'alternate_email' => 'me@example.com', 'aup' => true]);
        $this->act($i, $this->hod);
        $this->act($i, $this->operator);

        $this->assertSame('completed', $i->fresh()->status);
        $this->assertSame(['initiator.cse@institute.test' => 'active'], $mail->boxes);
        $this->assertSame('automation', ItAccount::query()->sole()->provisioned_by);
        $this->assertSame(0, WorkflowTask::query()->where('kind', 'manual')->count());
    }

    public function test_wifi_is_granted_through_the_directory_group(): void
    {
        $i = $this->start('it-wifi', $this->student, ['devices' => [['device' => 'laptop', 'mac' => '']], 'valid_until' => now()->addYear()->toDateString(), 'aup' => true]);
        $this->act($i, $this->operator);

        $this->assertSame('completed', $i->fresh()->status);
        $this->assertSame([$this->student->id], $this->network->members['wifi']);
        $this->assertSame(1, ItAccount::query()->where(['user_id' => $this->student->id, 'kind' => 'wifi', 'status' => 'active'])->count());
    }

    public function test_vm_requests_above_quota_need_an_exception(): void
    {
        $big = $this->start('it-vm', $this->student, ['purpose' => 'ML', 'project' => 'Thesis', 'os' => 'ubuntu-24.04', 'cpu' => 16, 'ram_gb' => 64, 'disk_gb' => 100, 'network' => 'internal', 'valid_until' => now()->addMonths(6)->toDateString()]);
        $task = $big->tasks()->where('status', 'pending')->sole();
        $this->assertSame([$this->ccHead->id, 'quota_exception'], [$task->assignee_id, $task->node_key]);
        $this->assertStringContainsString('Above quota', (string) IntegrationJob::query()->value('last_error'));

        $ok = $this->start('it-vm', $this->initiator, ['purpose' => 'Course', 'project' => 'CS101', 'os' => 'ubuntu-24.04', 'cpu' => 2, 'ram_gb' => 4, 'disk_gb' => 40, 'network' => 'public', 'ports' => '443', 'valid_until' => now()->addMonths(6)->toDateString()]);
        $this->act($ok, $this->hod);
        $this->assertSame('cc_head', $ok->tasks()->where('status', 'pending')->value('node_key'), 'public IP needs the Head, CC');
        $this->act($ok, $this->ccHead);
        $this->assertSame('manual', $ok->tasks()->where('status', 'pending')->value('kind'), 'no hypervisor configured: manual');
    }

    public function test_equipment_is_issued_from_the_register_and_cannot_be_issued_twice(): void
    {
        $asset = app(AssetService::class)->register(['asset_number' => 'LAP-001', 'category' => 'laptop', 'model' => 'ThinkPad'], $this->operator);
        $other = $this->person('Colleague', 'faculty', $this->cse);
        $other->forceFill(['reports_to_id' => $this->hod->id])->save();

        $first = $this->start('it-asset', $this->initiator, ['category' => 'laptop', 'justification' => 'Field work']);
        $second = $this->start('it-asset', $other, ['category' => 'laptop', 'justification' => 'Teaching']);
        foreach ([$first, $second] as $i) {
            $this->act($i, $this->hod);
        }
        $this->act($first, $this->operator, 'approve', ['asset_number' => 'LAP-001']);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame([$this->initiator->id, 'issued'], [$asset->fresh()->custodian_id, $asset->fresh()->status]);

        // The store picks the same laptop by mistake: the issue fails and returns to the store.
        $this->act($second, $this->operator, 'approve', ['asset_number' => 'LAP-001']);
        $this->assertSame('in_progress', $second->fresh()->status);
        $this->assertSame('store', $second->tasks()->where('status', 'pending')->value('node_key'));
        $this->assertSame($this->initiator->id, $asset->fresh()->custodian_id);
    }

    public function test_software_seats_are_counted(): void
    {
        $title = SoftwareTitle::create(['code' => 'matlab', 'name' => 'MATLAB', 'licence_type' => 'per_seat', 'seats' => 1, 'is_active' => true]);
        app(LicenceService::class)->allocate($title, $this->hod);

        $i = $this->start('it-software', $this->initiator, ['software' => 'matlab', 'purpose' => 'Research']);
        $this->act($i, $this->hod);
        $this->assertSame('cc_waitlist', $i->tasks()->where('status', 'pending')->value('node_key'), 'no free seat');

        $this->expectException(ValidationException::class);
        $this->start('it-software', $this->initiator, ['software' => 'not-in-register', 'purpose' => 'x']);
    }

    public function test_no_dues_opens_a_reviewed_deprovisioning_that_revokes_access_once(): void
    {
        // The leaver has Wi-Fi, a mailbox, a licence and (until returned) a laptop.
        $leaver = $this->initiator;
        $this->network->grant($leaver, 'wifi');
        $ledger = app(AccountLedger::class);
        $ledger->record($leaver, 'wifi', 'cn=wifi-users,ou=groups,dc=test', [], null);
        $ledger->record($leaver, 'email', 'leaver@institute.test', [], null);
        $title = SoftwareTitle::create(['code' => 'office', 'name' => 'Office', 'licence_type' => 'per_seat', 'seats' => 5, 'is_active' => true]);
        app(LicenceService::class)->allocate($title, $leaver);
        $laptop = app(AssetService::class)->register(['asset_number' => 'LAP-9', 'category' => 'laptop'], $this->operator);
        app(AssetService::class)->move($laptop, 'issue', $this->operator, $leaver);

        $noDues = $this->start('no-dues', $leaver, ['last_working_day' => now()->addWeek()->toDateString(), 'reason' => 'resignation']);
        $this->assertSame('cc_clear', $noDues->tasks()->where('assignee_id', $this->operator->id)->value('node_key'), 'laptop outstanding');

        app(AssetService::class)->move($laptop, 'return', $this->operator);
        $this->act($noDues, $this->operator);
        $this->act($noDues, $this->hod);
        $this->act($noDues, $this->finance);
        $this->act($noDues, $this->registrar);
        $this->assertSame('completed', $noDues->fresh()->status);

        // Approval of no-dues closed nothing; it opened a review.
        $this->assertSame([$leaver->id], $this->network->members['wifi']);
        $review = WorkflowInstance::query()->where('variables->parent', $noDues->reference)->sole();
        $this->assertSame($leaver->id, $review->data['account_holder']);

        $this->act($review, $this->operator, 'approve', ['mailbox_retention_days' => 180, 'review_notes' => 'No research data held']);
        $this->act($review, $this->ccHead);

        // Network revoked automatically; the mailbox needs a person (no adapter).
        $this->assertSame([], $this->network->members['wifi']);
        $this->assertSame('deprovisioned', ItAccount::query()->where('kind', 'wifi')->value('status'));
        $this->assertSame('active', ItAccount::query()->where('kind', 'email')->value('status'));
        $this->assertNotNull(LicenceAllocation::query()->value('revoked_at'));
        $manual = $review->tasks()->where('status', 'pending')->sole();
        $this->assertSame('manual', $manual->kind);
        $this->assertStringContainsString('email leaver@institute.test', IntegrationJob::query()->where('action', 'cc.deprovision')->value('last_error'));

        // Running the step again does not touch what is already closed.
        $calls = $this->network->calls;
        $action = app(Deprovision::class);
        $action->run(IntegrationJob::query()->where('action', 'cc.deprovision')->sole(), $review->fresh(), []);
        $this->assertSame($calls, $this->network->calls);

        $this->act($review, $this->operator, 'complete', remarks: 'Mailbox suspended in console');
        $this->assertSame('completed', $review->fresh()->status);
    }

    public function test_a_legal_hold_stops_deprovisioning(): void
    {
        $this->network->grant($this->initiator, 'wifi');
        app(AccountLedger::class)->record($this->initiator, 'wifi', 'cn=wifi-users', [], null);
        $admin = $this->designer;

        $review = $this->start('it-deprovision', $admin, ['account_holder' => $this->initiator->id, 'reason' => 'resignation', 'last_working_day' => now()->toDateString()]);
        $this->act($review, $this->operator, 'approve', ['legal_hold' => true, 'review_notes' => 'Inquiry pending']);

        $review->refresh();
        $this->assertSame('rejected', $review->status);
        $this->assertSame([$this->initiator->id], $this->network->members['wifi'], 'nothing changed');
    }

    public function test_computer_centre_pages_need_the_permission(): void
    {
        foreach (['/computer-centre/assets', '/computer-centre/licences', '/computer-centre/accounts'] as $url) {
            $this->actingAs($this->initiator)->get($url)->assertForbidden();
            $this->actingAs($this->operator)->get($url)->assertOk();
        }
        $this->actingAs($this->operator)->post('/computer-centre/assets', ['asset_number' => 'MON-1', 'category' => 'monitor'])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['action' => 'cc.asset.registered']);
    }
}
