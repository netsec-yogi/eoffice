<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\BudgetEntry;
use App\Modules\Finance\Models\GoodsReceipt;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\ProcurementRuleSet;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\ProcurementRules;
use App\Modules\Finance\Services\ProcurementService;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

/** PROC-001/002, FIN-002, VEND-001/002: the purchase lifecycle on configurable rules. */
class ProcurementTest extends TestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    private function position(): array
    {
        return app(BudgetService::class)->position($this->head->fresh());
    }

    public function test_indent_records_the_rule_decision_and_commits_funds_at_the_budget_check(): void
    {
        $indent = $this->indent();
        $this->assertEquals(60000, $indent->data['estimated_value']);
        $this->assertSame(['quotations', true, 3], [$indent->data['procurement_method'], $indent->data['committee_required'], $indent->data['quotations_required']]);
        $case = ProcurementCase::query()->sole();
        $this->assertSame(['indent', 1, 'Medium'], [$case->status, $case->decision['rule_version'], $case->decision['label']]);
        $this->assertSame(0.0, $this->position()['committed']);

        $this->toSourcing($indent);
        $this->assertSame('budgeted', $case->fresh()->status);
        $this->assertEquals(['allocated' => 1000000, 'committed' => 60000, 'spent' => 0, 'available' => 940000], $this->position());
        $this->assertTrue($indent->fresh()->tasks()->where('status', 'pending')->where('assignee_id', $this->purchaseOfficer->id)->exists());
    }

    public function test_indent_beyond_available_funds_or_another_departments_head_is_refused(): void
    {
        try {
            $this->indent([['description' => 'Server', 'qty' => 1, 'unit' => 'nos', 'est_unit_price' => 1500000]]);
            $this->fail('over-budget indent accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('available', $e->errors()['budget_head'][0]);
        }

        $this->head->forceFill(['department_id' => $this->ece->id])->save();
        $this->expectException(ValidationException::class);
        $this->indent();
    }

    public function test_incomplete_evaluation_goes_back_to_the_purchase_officer_with_the_gaps(): void
    {
        $case = $this->toSourcing();
        $indent = WorkflowInstance::query()->findOrFail($case->workflow_instance_id);
        $this->act($indent, $this->purchaseOfficer);

        $back = $indent->fresh()->tasks()->where('status', 'pending')->sole();
        $this->assertSame($this->purchaseOfficer->id, $back->assignee_id);
        $failure = IntegrationJob::query()->where('workflow_instance_id', $indent->id)->where('node_key', 'check')->latest('id')->firstOrFail();
        $this->assertStringContainsString('3 qualified quotation(s) needed under rule version 1', (string) $failure->last_error.json_encode($failure->result));
    }

    public function test_selecting_other_than_l1_needs_a_justification(): void
    {
        $case = $this->toSourcing();
        $service = app(ProcurementService::class);
        $l1 = $service->addQuotation($case, $this->acme, 50000, true, null, $this->purchaseOfficer);
        $l2 = $service->addQuotation($case, $this->zenith, 52000, true, null, $this->purchaseOfficer);
        $service->addQuotation($case, Vendor::query()->where('code', 'ORBIT')->sole(), 40000, false, 'Fails spec', $this->purchaseOfficer);

        $this->assertSame(['L1', 'L2', '—'], array_column($service->comparative($case), 'rank'));
        try {
            $service->select($case, $l2, null, $this->purchaseOfficer);
            $this->fail('L2 selected without justification');
        } catch (WorkflowException $e) {
            $this->assertArrayHasKey('justification', $e->errors);
        }
        $service->select($case, $l2, 'Earlier delivery date is essential', $this->purchaseOfficer);
        $this->assertSame($l2->id, $case->fresh()->selected_quotation_id);
        $this->assertNotSame($l1->id, $case->fresh()->selected_quotation_id);
    }

    public function test_full_lifecycle_from_indent_to_payment(): void
    {
        $order = $this->ordered(58000);
        $case = $order->procurementCase;
        $this->assertSame(['issued', 'ordered', 58000.0], [$order->status, $case->status, $order->total]);
        $this->assertEquals(58000, $this->position()['committed']); // commitment follows the order value
        $this->assertNotNull($order->document_id);
        $this->assertTrue(NotificationMessage::query()->where('user_id', $this->acmeUser->id)->where('template_key', 'po.issued')->exists());

        // A bill before acceptance is refused (three-way match).
        try {
            $this->bill($order, 58000);
            $this->fail('bill before goods acceptance accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('purchase_order', $e->errors());
        }

        $grn = $this->receive($order);
        $this->assertSame('completed', $grn->fresh()->status);
        $this->assertSame(['accepted', 58000.0], [GoodsReceipt::query()->sole()->status, GoodsReceipt::query()->sole()->accepted_value]);
        $this->assertSame('received', $order->fresh()->status);

        // Another vendor cannot bill ACME's order; ACME cannot bill more than was accepted.
        $this->expectValidation(fn () => $this->bill($order, 1000, 'Z-1', $this->zenithUser), 'purchase_order');
        $this->expectValidation(fn () => $this->bill($order, 60000), 'invoice_amount');

        $bill = $this->bill($order, 58000);
        $invoice = VendorInvoice::query()->sole();
        $this->assertSame('submitted', $invoice->status);

        // Clarification goes back to the vendor, who answers it.
        $this->act($bill, $this->financeClerk, 'return', remarks: 'Quote the PO number on the invoice.');
        $this->assertSame('returned', $bill->fresh()->status);
        app(WorkflowEngine::class)->resubmit($bill->fresh(), $this->acmeUser, ['invoice_file' => $this->pdf($this->acmeUser, 'corrected.pdf')] + $bill->fresh()->data, 'Corrected');

        $this->act($bill, $this->financeClerk, 'approve', ['deductions' => 1160]);
        $this->assertSame(['verified', 56840.0], [$invoice->fresh()->status, $invoice->fresh()->net_payable]);
        $this->act($bill, $this->financeOfficer);
        $this->assertSame('sanctioned', $invoice->fresh()->status);
        $this->act($bill, $this->financeClerk, 'approve', ['payment_reference' => 'UTR123456', 'paid_on' => '2026-10-21']);

        $invoice->refresh();
        $this->assertSame(['completed', 'paid', 'UTR123456'], [$bill->fresh()->status, $invoice->status, $invoice->payment_reference]);
        $this->assertNotNull($invoice->payment_advice_id);
        $this->assertSame(['closed', 'closed'], [$order->fresh()->status, $case->fresh()->status]);
        $this->assertEquals(['allocated' => 1000000, 'committed' => 0, 'spent' => 58000, 'available' => 942000], $this->position());
        $this->assertTrue(NotificationMessage::query()->where('user_id', $this->acmeUser->id)->where('template_key', 'invoice.paid')->exists());

        $this->assertSame(0, Artisan::call('audit:verify'));
    }

    public function test_part_bills_cannot_reuse_an_invoice_number_or_exceed_what_is_left(): void
    {
        $order = $this->ordered(58000);
        $this->receive($order);
        $this->bill($order, 30000, 'INV-7');
        $this->expectValidation(fn () => $this->bill($order, 1000, 'INV-7'), 'invoice_number');
        $this->expectValidation(fn () => $this->bill($order, 28001, 'INV-8'), 'invoice_amount');
        $this->bill($order, 28000, 'INV-8');
        $this->assertSame(2, VendorInvoice::query()->count());
    }

    public function test_rejected_indent_releases_its_commitment(): void
    {
        $case = $this->toSourcing();
        $this->assertEquals(60000, $this->position()['committed']);
        $indent = WorkflowInstance::query()->findOrFail($case->workflow_instance_id);
        app(WorkflowEngine::class)->cancel($indent->fresh(), $this->initiator, 'No longer needed');

        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertEquals(0, $this->position()['committed']);
    }

    public function test_rule_sets_are_versioned_and_published_rules_are_immutable(): void
    {
        $rules = app(ProcurementRules::class);
        $v1 = ProcurementRuleSet::current();
        $first = $this->indent();

        $this->assertNotEmpty($rules->problems([['up_to' => 5000, 'method' => 'direct', 'label' => 'x', 'quotations' => 0]])); // last band must be open
        $draft = $rules->saveDraft(null, ['title' => 'v2', 'authority' => 'Test O.M.', 'bands' => [['up_to' => null, 'method' => 'gem', 'label' => 'All', 'quotations' => 1, 'committee' => false]]], $this->financeManager);
        $rules->publish($draft, $this->financeManager);

        $this->assertSame('retired', $v1->fresh()->status);
        $this->assertSame(2, ProcurementRuleSet::current()->version);
        $this->assertSame(1, ProcurementCase::query()->where('workflow_instance_id', $first->id)->sole()->decision['rule_version']); // earlier cases keep their decision
        $this->assertSame('gem', $this->indent()->data['procurement_method']);

        $this->expectException(\LogicException::class);
        $v1->fresh()->forceFill(['bands' => []])->save();
    }

    public function test_budget_ledger_is_append_only(): void
    {
        $entry = BudgetEntry::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $entry->forceFill(['amount' => 1])->save();
    }

    public function test_advance_is_sanctioned_disbursed_and_settled_with_a_balance(): void
    {
        $adv = $this->start('advance', $this->initiator, ['purpose' => 'Field trip', 'amount_requested' => 20000, 'settle_by' => '2026-11-30']);
        $this->act($adv, $this->hod);
        $this->act($adv, $this->financeOfficer, 'approve', ['amount_sanctioned' => 15000]);
        $this->act($adv, $this->financeClerk, 'approve', ['disbursement_reference' => 'CHQ-77']);
        $advance = Advance::query()->sole();
        $this->assertSame(['disbursed', 15000.0], [$advance->status, $advance->amount_sanctioned]);

        $settlement = $this->start('advance-settlement', $this->initiator, ['advance' => (string) $advance->id, 'expenses' => [['date' => '2026-11-02', 'details' => 'Bus hire', 'amount' => 12000]], 'receipts' => [$this->pdf($this->initiator, 'r.pdf')]]);
        $this->act($settlement, $this->financeClerk, 'approve', ['refund_reference' => 'RCPT-9']);
        $this->act($settlement, $this->financeOfficer);
        $advance->refresh();
        $this->assertSame(['settled', 12000.0, 3000.0], [$advance->status, $advance->spent, $advance->balance]);

        $this->travelTo('2026-12-15');
        $this->assertFalse($advance->isOverdue());
    }

    private function expectValidation(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("expected a validation error on {$key}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), json_encode($e->errors()));
        }
    }
}
