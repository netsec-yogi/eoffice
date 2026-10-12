<?php

namespace Tests\Feature\Registrar;

use App\Modules\Documents\DocumentStore;
use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\ReimbursementClaim;
use App\Modules\Registrar\Models\TravelAuthorisation;
use App\Modules\Workflow\Engine\WorkflowEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Support\RegistrarFixtures;
use Tests\TestCase;

/** REG-001: tour, TA/DA, LTC, medical reimbursement */
class TravelClaimsTest extends TestCase
{
    use RegistrarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistrar();
    }

    private function receipt(User $who): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n%%EOF");

        return app(DocumentStore::class)->store(new UploadedFile($path, 'ticket.pdf', null, null, true), null, $who)->uuid;
    }

    private function approvedTour(): TravelAuthorisation
    {
        $tour = $this->start('tour', $this->initiator, ['purpose' => 'Conference', 'destination' => 'Pune', 'from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'mode' => 'rail', 'estimated_cost' => 20000, 'funding' => 'institute', 'advance_requested' => 10000]);
        $this->act($tour, $this->hod);
        $this->act($tour, $this->registrar, 'approve', ['advance_sanctioned' => 8000]);

        return TravelAuthorisation::query()->where('workflow_instance_id', $tour->id)->sole();
    }

    public function test_tour_claim_is_verified_sanctioned_paid_and_nets_the_advance(): void
    {
        $tour = $this->approvedTour();
        $this->assertSame(['approved', 8000.0], [$tour->status, $tour->advance_sanctioned]);

        $claim = $this->start('tada-claim', $this->initiator, [
            'authorisation' => (string) $tour->id,
            'bills' => [['date' => '2026-11-10', 'head' => 'fare', 'amount' => 6000], ['date' => '2026-11-12', 'head' => 'lodging', 'amount' => 7000]],
            'receipts' => [$this->receipt($this->initiator)], 'declaration' => true,
        ]);
        $this->assertEquals(13000, $claim->data['total']); // JSONB stores 13000.0 as 13000
        $this->assertSame('claimed', $tour->fresh()->claim_status);

        $this->act($claim, $this->financeClerk, 'approve', ['admissible_amount' => 12500]);
        $this->act($claim, $this->financeOfficer);
        $record = ReimbursementClaim::query()->sole();
        $this->assertSame(['sanctioned', 4500.0], [$record->status, $record->net_payable]);

        $this->act($claim, $this->financeClerk, 'approve', ['payment_reference' => 'VCH/2026/118', 'paid_on' => '2026-11-20']);
        $this->assertSame('completed', $claim->fresh()->status);
        $this->assertSame(['paid', 'VCH/2026/118'], [$record->fresh()->status, $record->fresh()->payment_reference]);
        $this->assertSame('settled', $tour->fresh()->claim_status);
    }

    public function test_claims_must_match_the_journey_and_are_one_per_journey(): void
    {
        $tour = $this->approvedTour();
        $base = ['authorisation' => (string) $tour->id, 'receipts' => [$this->receipt($this->initiator)], 'declaration' => true];

        try {
            $this->start('tada-claim', $this->initiator, $base + ['bills' => [['date' => '2026-12-01', 'head' => 'fare', 'amount' => 500]]]);
            $this->fail('bill outside the journey accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bills.0.date', $e->errors());
        }
        $this->start('tada-claim', $this->initiator, $base + ['bills' => [['date' => '2026-11-11', 'head' => 'fare', 'amount' => 500]]]);

        $this->expectException(ValidationException::class);
        $this->start('tada-claim', $this->initiator, ['authorisation' => (string) $tour->id, 'receipts' => [$this->receipt($this->initiator)], 'declaration' => true, 'bills' => [['date' => '2026-11-11', 'head' => 'fare', 'amount' => 500]]]);
    }

    public function test_someone_elses_journey_cannot_be_claimed(): void
    {
        $tour = $this->approvedTour();
        $this->expectException(ValidationException::class);
        $this->start('tada-claim', $this->hod, ['authorisation' => (string) $tour->id, 'bills' => [['date' => '2026-11-11', 'head' => 'fare', 'amount' => 1]], 'receipts' => [$this->receipt($this->hod)], 'declaration' => true]);
    }

    public function test_a_rejected_claim_frees_the_journey_for_a_new_claim(): void
    {
        $tour = $this->approvedTour();
        $claim = $this->start('tada-claim', $this->initiator, ['authorisation' => (string) $tour->id, 'bills' => [['date' => '2026-11-11', 'head' => 'fare', 'amount' => 900]], 'receipts' => [$this->receipt($this->initiator)], 'declaration' => true]);
        $this->act($claim, $this->financeClerk, 'reject', remarks: 'Bills illegible');
        $this->assertSame('none', $tour->fresh()->claim_status);
        $this->start('tada-claim', $this->initiator, ['authorisation' => (string) $tour->id, 'bills' => [['date' => '2026-11-11', 'head' => 'fare', 'amount' => 900]], 'receipts' => [$this->receipt($this->initiator)], 'declaration' => true]);
        $this->assertSame(2, ReimbursementClaim::query()->count());
    }

    public function test_ltc_is_limited_per_block(): void
    {
        $data = ['purpose' => 'hometown', 'destination' => 'Varanasi', 'from_date' => '2026-12-20', 'to_date' => '2026-12-27', 'travellers' => [['name' => 'Self', 'relation' => 'self', 'age' => 40]]];
        $first = $this->start('ltc', $this->initiator, $data);
        $this->assertSame('2026-2029', $first->data['block']);

        $this->expectException(ValidationException::class);
        $this->start('ltc', $this->initiator, [...$data, 'from_date' => '2027-05-01', 'to_date' => '2027-05-07']);
    }

    public function test_medical_claim_flow(): void
    {
        $claim = $this->start('medical-claim', $this->initiator, ['patient' => 'spouse', 'patient_name' => 'R. Kumar', 'hospital' => 'City Hospital', 'treatment' => 'opd',
            'bills' => [['date' => '2026-10-15', 'details' => 'Bill 551', 'amount' => 2400]], 'prescriptions' => [$this->receipt($this->initiator)]]);
        $this->act($claim, $this->financeClerk, 'return', remarks: 'Attach the prescription');
        $this->assertSame('returned', $claim->fresh()->status);
        app(WorkflowEngine::class)->resubmit($claim->fresh(), $this->initiator, $claim->fresh()->data, 'Attached');
        $this->act($claim, $this->financeClerk);
        $this->act($claim, $this->financeOfficer);
        $this->assertSame(2400.0, ReimbursementClaim::query()->sole()->net_payable);
    }

    public function test_claims_register_is_scoped(): void
    {
        $this->approvedTour();
        $this->actingAs($this->hod)->get('/claims')->assertOk()->assertInertia(fn ($p) => $p->has('journeys', 0)->where('register', false));
        $this->actingAs($this->financeClerk)->get('/claims?all=1')->assertOk()->assertInertia(fn ($p) => $p->has('journeys', 1)->where('register', true));
    }
}
