<?php

namespace Tests\Feature\Registrar;

use App\Modules\Registrar\Models\LeaveLedgerEntry;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Services\LeaveService;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;
use Tests\Support\RegistrarFixtures;
use Tests\TestCase;

/** LEAVE-001 */
class LeaveTest extends TestCase
{
    use RegistrarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistrar();
        $this->credit($this->initiator, 'CL', 8);
        $this->credit($this->initiator, 'EL', 60);
    }

    private function balance(string $code): float
    {
        return app(LeaveService::class)->available($this->initiator, LeaveType::query()->where('code', $code)->sole());
    }

    private function apply(array $data): WorkflowInstance
    {
        return $this->start('leave', $this->initiator, $data + ['reason' => 'Personal work']);
    }

    public function test_working_days_exclude_weekends_and_holidays_and_balance_is_debited_on_sanction(): void
    {
        // Mon 2 Nov – Fri 6 Nov with Wed 4 Nov a holiday: 4 working days of CL.
        $i = $this->apply(['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-06']);
        $this->assertEquals(4.0, $i->data['days']);
        $this->assertSame(4.0, $this->balance('CL'), 'held while pending');
        $this->assertSame('pending', LeaveRecord::query()->sole()->status);

        $this->act($i, $this->hod);
        $this->assertSame('completed', $i->fresh()->status);
        $this->assertSame('approved', LeaveRecord::query()->sole()->status);
        $this->assertSame(4.0, $this->balance('CL'));
        $this->assertSame(4.0, app(LeaveService::class)->ledgerBalance($this->initiator, LeaveType::query()->where('code', 'CL')->sole()));
    }

    public function test_earned_leave_counts_calendar_days(): void
    {
        $i = $this->apply(['leave_type' => 'EL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-08']);
        $this->assertEquals(7.0, $i->data['days']);
    }

    public function test_rules_are_enforced_at_submission(): void
    {
        $cases = [
            [['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-13'], 'to_date'],     // 9 working days > max 5 per application
            [['leave_type' => 'EL', 'from_date' => '2026-11-02', 'to_date' => '2027-01-10'], 'leave_type'],  // 70 days > 60 balance
            [['leave_type' => 'EL', 'from_date' => '2026-10-21', 'to_date' => '2026-10-22'], 'from_date'],   // EL needs 7 days notice
            [['leave_type' => 'CL', 'from_date' => '2026-11-03', 'to_date' => '2026-11-02'], 'to_date'],     // reversed
            [['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03', 'half_day' => true], 'half_day'],
            [['leave_type' => 'CL', 'from_date' => '2026-11-07', 'to_date' => '2026-11-08'], 'to_date'],     // weekend only
            [['leave_type' => 'HPL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-06'], 'leave_type'], // no HPL balance
        ];
        foreach ($cases as [$data, $field]) {
            try {
                $this->apply($data);
                $this->fail('accepted: '.json_encode($data));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), json_encode($data).' -> '.json_encode($e->errors()));
            }
        }
    }

    public function test_overlaps_and_pending_holds_are_respected(): void
    {
        $this->apply(['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
        try {
            $this->apply(['leave_type' => 'EL', 'from_date' => '2026-11-03', 'to_date' => '2026-11-05']);
            $this->fail('overlap accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Overlaps', $e->errors()['from_date'][0]);
        }
        $half = $this->apply(['leave_type' => 'CL', 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'half_day' => true]);
        $this->assertEquals(0.5, $half->data['days']);
        $this->assertSame(5.5, $this->balance('CL'));
    }

    public function test_rejection_releases_the_hold(): void
    {
        $i = $this->apply(['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
        $this->act($i, $this->hod, 'reject', remarks: 'Exams in progress');
        $this->assertSame('rejected', LeaveRecord::query()->sole()->status);
        $this->assertSame(8.0, $this->balance('CL'));
    }

    public function test_long_earned_leave_needs_the_registrar(): void
    {
        $i = $this->apply(['leave_type' => 'EL', 'from_date' => '2026-11-02', 'to_date' => '2026-12-11']); // 40 days
        $this->act($i, $this->hod);
        $this->assertSame('registrar', $i->tasks()->where('status', 'pending')->value('node_key'));
    }

    public function test_certificate_required_for_long_half_pay_leave(): void
    {
        $this->credit($this->initiator, 'HPL', 20);
        $this->expectException(ValidationException::class);
        $this->apply(['leave_type' => 'HPL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-06']);
    }

    public function test_cancellation_and_curtailment_credit_back_unused_days(): void
    {
        $leave = $this->apply(['leave_type' => 'EL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-11']); // 10 days
        $this->act($leave, $this->hod);
        $this->assertSame(50.0, $this->balance('EL'));
        $record = LeaveRecord::query()->sole();

        $curtail = $this->start('leave-cancellation', $this->initiator, ['leave' => (string) $record->id, 'new_to_date' => '2026-11-05', 'reason' => 'Returning early']);
        $this->assertEquals(6.0, $curtail->data['days_restored']);
        $this->act($curtail, $this->hod);
        $this->assertSame([46.0, 'curtailed', '2026-11-05'], [0.0 + $this->balance('EL') - 10, $record->fresh()->status, $record->fresh()->to_date->toDateString()]);

        $cancel = $this->start('leave-cancellation', $this->initiator, ['leave' => (string) $record->id, 'reason' => 'Trip cancelled']);
        $this->act($cancel, $this->hod);
        $this->assertSame(60.0, $this->balance('EL'));
        $this->assertSame('cancelled', $record->fresh()->status);
    }

    public function test_duty_and_station_leave_keep_no_balance(): void
    {
        $i = $this->apply(['leave_type' => 'DL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
        $this->act($i, $this->hod);
        $this->assertSame('completed', $i->fresh()->status);
        $this->assertSame(0, LeaveLedgerEntry::query()->where('kind', 'debit')->count());
    }

    public function test_ledger_adjustments_are_permissioned_and_audited(): void
    {
        $type = LeaveType::query()->where('code', 'CL')->sole();
        $this->actingAs($this->initiator)->post('/leave/ledger', ['leave_type_id' => $type->id, 'kind' => 'credit', 'days' => 30, 'reason' => 'x', 'user_id' => $this->initiator->id])->assertForbidden();
        $this->actingAs($this->establishment)->post('/leave/ledger', ['leave_type_id' => $type->id, 'kind' => 'credit', 'days' => 2, 'reason' => 'Compensatory', 'user_id' => $this->initiator->id])->assertSessionHas('success');
        $this->assertSame(10.0, $this->balance('CL'));
        $this->assertDatabaseHas('audit_events', ['action' => 'leave.ledger.credit', 'actor_id' => $this->establishment->id]);
        $this->actingAs($this->initiator)->get('/leave')->assertOk()->assertInertia(fn ($p) => $p->where('balances', fn ($b) => collect($b)->firstWhere('code', 'CL')['available'] == 10));
    }
}
