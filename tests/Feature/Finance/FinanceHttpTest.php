<?php

namespace Tests\Feature\Finance;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\ProcurementRuleSet;
use App\Modules\Finance\Models\Quotation;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Workflow\Models\WorkflowInstance;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

/**
 * The exit criterion over HTTP: procurement progresses through its
 * configured lifecycle with permission checks, configurable rules and
 * auditable transitions, and vendors see only their own records.
 */
class FinanceHttpTest extends TestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    public function test_purchase_officer_sources_over_http_and_others_cannot(): void
    {
        $case = $this->toSourcing();

        $this->actingAs($this->initiator)->get("/purchases/{$case->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Procurement/Show')->where('canSource', false)->where('purchase.budget_head.available', null));
        $this->post("/purchases/{$case->id}/quotations", ['vendor_id' => $this->acme->id, 'amount' => 1])->assertForbidden();
        $this->actingAs($this->person('Outsider', 'staff', $this->ece))->get("/purchases/{$case->id}")->assertForbidden();

        $this->actingAs($this->purchaseOfficer);
        $this->get("/purchases/{$case->id}")->assertInertia(fn ($p) => $p->where('canSource', true)->has('gaps', 3));
        $this->post("/purchases/{$case->id}/sourcing", ['gem_status' => 'not_available'])->assertSessionHas('success');
        foreach ([[$this->acme, 59000], [$this->zenith, 57000], [Vendor::query()->where('code', 'ORBIT')->sole(), 61000]] as [$v, $amount]) {
            $this->post("/purchases/{$case->id}/quotations", ['vendor_id' => $v->id, 'amount' => $amount, 'technically_qualified' => true])->assertSessionHas('success');
        }
        $acmeQuote = Quotation::query()->where('vendor_id', $this->acme->id)->sole();
        $this->post("/purchases/{$case->id}/select", ['quotation_id' => $acmeQuote->id])->assertSessionHasErrors('justification');
        $this->post("/purchases/{$case->id}/select", ['quotation_id' => Quotation::query()->where('vendor_id', $this->zenith->id)->value('id')])->assertSessionHas('success');
        $this->get("/purchases/{$case->id}")->assertInertia(fn ($p) => $p->has('gaps', 0)->where('comparative.0.rank', 'L1')->where('comparative.0.vendor_code', 'ZENITH'));

        $this->assertTrue(AuditEvent::query()->where('action', 'procurement.selected')->exists());
    }

    public function test_budget_and_rules_need_their_permissions(): void
    {
        $this->actingAs($this->initiator)->get('/budget')->assertForbidden();
        $this->get('/vendors')->assertForbidden();
        $this->post('/purchases/rules', [])->assertForbidden();
        $this->get('/purchases/rules')->assertForbidden();
        $this->actingAs($this->purchaseOfficer)->get('/purchases/rules')->assertOk()->assertInertia(fn ($p) => $p->where('canEdit', false));
        $this->post('/budget', ['code' => 'X', 'name' => 'X', 'financial_year' => '2026-27'])->assertForbidden();

        $this->actingAs($this->financeManager);
        $this->get('/budget')->assertOk()->assertInertia(fn ($p) => $p->component('Budget/Index')->where('heads.0.code', 'CSE-EQP')->where('heads.0.available', 1000000));
        $this->post("/budget/{$this->head->id}/entries", ['kind' => 'reappropriation', 'amount' => -2000000, 'description' => 'too much'])->assertSessionHasErrors('amount');
        $this->post("/budget/{$this->head->id}/entries", ['kind' => 'reappropriation', 'amount' => -100000, 'description' => 'To CSE consumables'])->assertSessionHas('success');
        $this->get("/budget/{$this->head->id}")->assertInertia(fn ($p) => $p->where('head.available', 900000)->has('entries', 2));

        $bands = [['up_to' => 50000, 'method' => 'direct', 'label' => 'A', 'quotations' => 0, 'committee' => false], ['up_to' => 40000, 'method' => 'gem', 'label' => 'B', 'quotations' => 1]];
        $this->post('/purchases/rules', ['title' => 'v2', 'authority' => 'O.M. (test)', 'bands' => $bands])->assertSessionHas('success');
        $draft = ProcurementRuleSet::query()->where('status', 'draft')->sole();
        $this->post("/purchases/rules/{$draft->id}/publish", ['confirm' => true])->assertSessionHasErrors('bands'); // limits must increase, last open
        $bands[1]['up_to'] = null;
        $this->post('/purchases/rules', ['title' => 'v2', 'authority' => 'O.M. (test)', 'bands' => $bands])->assertSessionHas('success');
        $this->post("/purchases/rules/{$draft->id}/publish", ['confirm' => true])->assertSessionHas('success');
        $this->assertSame([2, null], [ProcurementRuleSet::current()->version, ProcurementRuleSet::current()->bands[1]['up_to']]);
        $this->assertTrue(AuditEvent::query()->where('action', 'procurement.rules.published')->exists());
    }

    public function test_vendors_are_confined_to_their_own_portal(): void
    {
        $order = $this->ordered();
        $indent = WorkflowInstance::query()->findOrFail(ProcurementCase::query()->value('workflow_instance_id'));
        $po = $order->document()->value('uuid');

        $this->actingAs($this->acmeUser);
        $this->get('/')->assertRedirect('/vendor');
        $this->get('/vendor')->assertOk()->assertInertia(fn ($p) => $p->component('VendorPortal/Index')->has('orders', 1)->where('orders.0.number', $order->number)
            ->missing('orders.0.items.0.est_unit_price')->where('vendor.account', '••••••••2333'));
        $this->get("/documents/{$po}")->assertOk();
        foreach (['/purchases', "/purchases/{$order->procurement_case_id}", "/requests/{$indent->id}", '/tasks', '/files', '/requests', '/budget', '/advances'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->actingAs($this->zenithUser);
        $this->get('/vendor')->assertInertia(fn ($p) => $p->has('orders', 0)->has('invoices', 0));
        $this->get("/documents/{$po}")->assertForbidden();
        $this->get('/requests/new/purchase-indent')->assertForbidden();
    }

    public function test_vendor_sees_a_clarification_and_payment_but_never_internal_notes(): void
    {
        $order = $this->ordered();
        $this->receive($order);
        $bill = $this->bill($order, 58000);
        $this->act($bill, $this->financeClerk, 'return', ['deductions' => 580, 'verification_notes' => 'INTERNAL: check past delays'], 'Please quote the PO number on the invoice.');

        $this->actingAs($this->acmeUser);
        $this->get('/vendor')->assertInertia(fn ($p) => $p->where('invoices.0.status', 'clarification')
            ->where('invoices.0.clarification', 'Please quote the PO number on the invoice.')->where('invoices.0.edit_instance_id', $bill->id)->where('invoices.0.deductions', null));
        $edit = $this->get("/requests/{$bill->id}/edit")->assertOk();
        $this->assertStringNotContainsString('INTERNAL', $edit->getContent());
        $edit->assertInertia(fn ($p) => $p->missing('data.verification_notes')->missing('data.deductions'));

        // The vendor answers with only their own fields; the officers' entries survive.
        $own = array_intersect_key($bill->fresh()->data, array_flip(['purchase_order', 'invoice_number', 'invoice_date', 'invoice_amount', 'invoice_file']));
        $this->put("/requests/{$bill->id}", ['data' => $own, 'remarks' => 'PO number added'])->assertRedirect('/vendor');
        $this->assertSame('INTERNAL: check past delays', $bill->fresh()->data['verification_notes']);

        $this->act($bill, $this->financeClerk);
        $this->act($bill, $this->financeOfficer);
        $this->act($bill, $this->financeClerk, 'approve', ['payment_reference' => 'UTR-9', 'paid_on' => '2026-10-21']);
        $this->actingAs($this->acmeUser)->get('/vendor')->assertInertia(fn ($p) => $p->where('invoices.0.status', 'paid')->where('invoices.0.net_payable', 57420)->where('invoices.0.payment_reference', 'UTR-9')->whereType('invoices.0.payment_advice', 'string'));
        $this->assertStringNotContainsString('INTERNAL', $this->get('/vendor')->getContent());
    }

    public function test_vendor_register_masks_accounts_and_issues_portal_sign_ins(): void
    {
        $this->actingAs($this->purchaseOfficer);
        $this->post('/vendors', ['code' => 'NOVA', 'name' => 'Nova Instruments', 'email' => 'nova@vendors.test', 'bank_account' => '123456789012', 'ifsc' => 'TEST0001234', 'gstin' => '09ABCDE1234F1Z5'])->assertSessionHas('success');
        $nova = Vendor::query()->where('code', 'NOVA')->sole();
        $this->get('/vendors')->assertInertia(fn ($p) => $p->where('vendors.1.code', 'NOVA')->where('vendors.1.account', '••••••••9012')->missing('vendors.1.bank_account'));
        $this->assertNotSame('123456789012', \DB::table('vendors')->where('id', $nova->id)->value('bank_account')); // encrypted at rest

        $response = $this->post("/vendors/{$nova->id}/account")->assertSessionHas('success');
        preg_match('/temporary password (\S+)\./', session('success'), $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->assertSame('vendor', $nova->fresh()->user->category);
        $this->assertStringNotContainsString($m[1], AuditEvent::query()->get()->toJson());

        $this->post('/logout');
        $this->post('/login', ['username' => 'v-nova', 'password' => $m[1]])->assertRedirect('/');
        $this->get('/vendor')->assertOk();
    }
}
