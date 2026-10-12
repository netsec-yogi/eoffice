<?php

namespace Tests\Support;

use App\Modules\Calendar\Models\BusinessCalendar;
use App\Modules\Documents\DocumentStore;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\ProcurementRules;
use App\Modules\Finance\Services\ProcurementService;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Database\Seeders\FinanceWorkflowSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Organisation, the finance example workflows, a test rule set, a budget head and two vendors. */
trait FinanceFixtures
{
    use BuildsWorkflows;

    protected User $purchaseOfficer;

    protected User $financeClerk;

    protected User $financeOfficer;

    protected User $financeManager;

    protected User $inspector;

    protected BudgetHead $head;

    protected Vendor $acme;

    protected Vendor $zenith;

    protected User $acmeUser;

    protected User $zenithUser;

    protected function setUpFinance(): void
    {
        Mail::fake();
        Storage::fake('private');
        $this->setUpOrganisation();
        $this->designer->assignRole('system_admin');
        $this->seed(NotificationTemplateSeeder::class);
        $this->purchaseOfficer = tap($this->person('Purchase officer'))->assignRole('purchase_officer');
        $this->financeClerk = tap($this->person('Finance clerk'))->assignRole('finance_staff');
        $this->financeManager = tap($this->person('Finance manager'))->assignRole('finance_manager');
        $this->financeOfficer = $this->person('Finance Officer');
        $this->inspector = $this->person('Inspector', 'staff', $this->cse);
        $this->holds('FINANCE_OFFICER', $this->financeOfficer);
        $this->committee('PURCHASE_COMMITTEE', [[$this->person('Member A'), 1], [$this->person('Member B'), 1], [$this->person('Member C'), 1]]);

        BusinessCalendar::create(['code' => 'T', 'name' => 'Test', 'working_days' => [1, 2, 3, 4, 5], 'day_starts_at' => '09:00', 'day_ends_at' => '17:30', 'is_default' => true]);
        $this->travelTo('2026-10-20 10:00');
        $this->seed(FinanceWorkflowSeeder::class);

        // Test bands only: the shape matters, not the numbers.
        $rules = app(ProcurementRules::class);
        $rules->publish($rules->saveDraft(null, ['title' => 'Test rules', 'authority' => 'Test', 'bands' => [
            ['up_to' => 10000, 'method' => 'direct', 'label' => 'Small', 'quotations' => 0, 'committee' => false],
            ['up_to' => 200000, 'method' => 'quotations', 'label' => 'Medium', 'quotations' => 3, 'committee' => true],
            ['up_to' => null, 'method' => 'open_tender', 'label' => 'Large', 'quotations' => 3, 'committee' => true],
        ]], $this->financeManager), $this->financeManager);

        $this->head = BudgetHead::query()->create(['code' => 'CSE-EQP', 'name' => 'Equipment', 'financial_year' => BudgetService::financialYear(), 'department_id' => $this->cse->id, 'is_active' => true]);
        app(BudgetService::class)->post($this->head, 'allocation', 1000000, 'Test allocation', null, $this->financeManager);

        [$this->acme, $this->acmeUser] = $this->vendor('ACME');
        [$this->zenith, $this->zenithUser] = $this->vendor('ZENITH');
        Vendor::query()->create(['code' => 'ORBIT', 'name' => 'Orbit', 'is_active' => true]);
    }

    /** @return array{0: Vendor, 1: User} */
    protected function vendor(string $code): array
    {
        $user = User::factory()->create(['name' => $code, 'category' => 'vendor', 'auth_source' => 'local', 'password' => 'secret-for-tests']);
        $vendor = Vendor::query()->create(['code' => $code, 'name' => "{$code} Ltd", 'email' => strtolower($code).'@vendors.test', 'bank_account' => '000111222333', 'is_active' => true]);
        $vendor->forceFill(['user_id' => $user->id])->save();

        return [$vendor, $user->fresh()];
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

    protected function pdf(User $who, string $name = 'invoice.pdf'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n%%EOF");

        return app(DocumentStore::class)->store(new UploadedFile($path, $name, null, null, true), null, $who)->uuid;
    }

    protected function indent(array $items = [['description' => 'Oscilloscope', 'qty' => 2, 'unit' => 'nos', 'est_unit_price' => 30000]]): WorkflowInstance
    {
        return $this->start('purchase-indent', $this->initiator, ['title' => 'Lab equipment', 'justification' => 'Teaching lab', 'budget_head' => (string) $this->head->id, 'items' => $items]);
    }

    /** Indent through HoD and budget check to the purchase officer. */
    protected function toSourcing(?WorkflowInstance $indent = null): ProcurementCase
    {
        $indent ??= $this->indent();
        $this->act($indent, $this->hod);
        $this->act($indent, $this->financeClerk);

        return ProcurementCase::query()->where('workflow_instance_id', $indent->id)->sole();
    }

    /** Through to an issued order with ACME at the given price. */
    protected function ordered(float $price = 58000): PurchaseOrder
    {
        $case = $this->toSourcing();
        $service = app(ProcurementService::class);
        $service->recordSourcing($case, ['gem_status' => 'not_available'], $this->purchaseOfficer);
        $l1 = $service->addQuotation($case, $this->acme, $price, true, null, $this->purchaseOfficer);
        $service->addQuotation($case, $this->zenith, $price + 2000, true, null, $this->purchaseOfficer);
        $service->addQuotation($case, Vendor::query()->where('code', 'ORBIT')->sole(), $price + 4000, true, null, $this->purchaseOfficer);
        $service->select($case->fresh(), $l1, null, $this->purchaseOfficer);
        $indent = WorkflowInstance::query()->findOrFail($case->workflow_instance_id);
        $this->act($indent, $this->purchaseOfficer);
        foreach (Committee::query()->where('code', 'PURCHASE_COMMITTEE')->sole()->members()->with('user')->get() as $m) {
            if ($indent->tasks()->where('status', 'pending')->where('assignee_id', $m->user_id)->exists()) {
                $this->act($indent, $m->user);
            }
        }
        $this->act($indent, $this->registrar);

        return PurchaseOrder::query()->where('procurement_case_id', $case->id)->sole();
    }

    protected function receive(PurchaseOrder $order, int $accepted = 2): WorkflowInstance
    {
        $grn = $this->start('goods-receipt', $this->initiator, ['purchase_order' => (string) $order->id, 'received_on' => '2026-10-20', 'inspector' => $this->inspector->id,
            'received_items' => [['description' => 'Oscilloscope', 'received' => 2, 'accepted' => $accepted]]]);
        $this->act($grn, $this->inspector);

        return $grn;
    }

    protected function bill(PurchaseOrder $order, float $amount, string $number = 'INV-001', ?User $vendorUser = null): WorkflowInstance
    {
        $vendorUser ??= $this->acmeUser;

        return $this->start('vendor-invoice', $vendorUser, ['purchase_order' => (string) $order->id, 'invoice_number' => $number, 'invoice_date' => '2026-10-20', 'invoice_amount' => $amount, 'invoice_file' => $this->pdf($vendorUser)]);
    }
}
