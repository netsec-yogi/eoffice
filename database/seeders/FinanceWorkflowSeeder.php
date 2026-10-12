<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\User;
use Database\Seeders\Concerns\DefinesWorkflows;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Finance and purchase workflows (Phase 6). The ROUTES are examples; in
 * particular who may approve purchases of what value is the institute's
 * delegation of financial powers, which Finance must enter. Procurement
 * METHODS come from the published rule set, never from this file.
 */
class FinanceWorkflowSeeder extends Seeder
{
    use DefinesWorkflows;

    private const NOTE = ' (Example route: confirm with Finance and Purchase.)';

    public function run(): void
    {
        $this->actor = User::role('system_admin')->first() ?? throw new RuntimeException('Seed a system administrator first.');
        foreach ([['FINANCE_OFFICER', 'Finance Officer', 'institute'], ['REGISTRAR', 'Registrar', 'institute'], ['DIRECTOR', 'Director', 'institute'], ['HOD', 'Head of Department', 'department']] as [$code, $name, $scope]) {
            Authority::query()->firstOrCreate(['code' => $code], ['name' => $name, 'scope' => $scope]);
        }
        Committee::query()->firstOrCreate(['code' => 'PURCHASE_COMMITTEE'], ['name' => 'Purchase Committee', 'purpose' => 'Examines quotations and recommends purchases where the procurement rules require a committee. Members are appointed by office order.']);
        foreach (['purchase_officer', 'finance_staff', 'finance_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->indent();
        $this->receipt();
        $this->invoice();
        $this->advance();
        $this->settlement();
    }

    private function reg(string $key, string $name, string $step, array $extra = []): array
    {
        return [$key, 'automation', $name, ['action' => 'finance.register', 'settings' => ['step' => $step], 'on_blocked' => ['type' => 'role', 'role' => 'finance_staff']] + $extra];
    }

    private function role(string $key, string $type, string $name, string $role, array $extra = []): array
    {
        // $extra first: `+` keeps the left-hand keys, so overrides must lead.
        return [$key, $type, $name, $extra + ['assignees' => [['type' => 'role', 'role' => $role]], 'actions' => ['approve', 'reject', 'return'], 'sla' => ['duration' => 3, 'unit' => 'working_days']]];
    }

    private function authority(string $key, string $name, string $code, array $extra = []): array
    {
        return [$key, 'approval', $name, $extra + ['assignees' => [['type' => 'authority', 'authority' => $code] + ($code === 'HOD' ? ['department' => 'initiator'] : [])], 'actions' => ['approve', 'reject', 'return'], 'sla' => ['duration' => 3, 'unit' => 'working_days']]];
    }

    private function ends(): array
    {
        return [['done', 'end', 'Completed', ['outcome' => 'approved']], ['refused', 'end', 'Not approved', ['outcome' => 'rejected']]];
    }

    private function indent(): void
    {
        $this->define('purchase-indent', 'Purchase indent', 'Request goods or services. The procurement method follows the published procurement rules; funds are committed at the budget check.'.self::NOTE, 'procurement', [
            ['key' => 'title', 'type' => 'text', 'label' => 'What is to be purchased', 'required' => true],
            ['key' => 'justification', 'type' => 'textarea', 'label' => 'Justification', 'required' => true],
            ['key' => 'budget_head', 'type' => 'select', 'label' => 'Budget head', 'required' => true, 'source' => 'budget_heads'],
            ['key' => 'items', 'type' => 'table', 'label' => 'Items', 'required' => true, 'max_rows' => 30, 'columns' => [
                ['key' => 'description', 'type' => 'text', 'label' => 'Item', 'required' => true],
                ['key' => 'specification', 'type' => 'text', 'label' => 'Specification'],
                ['key' => 'qty', 'type' => 'number', 'label' => 'Qty', 'required' => true],
                ['key' => 'unit', 'type' => 'select', 'label' => 'Unit', 'required' => true, 'options' => array_map(fn ($u) => ['value' => $u, 'label' => $u], ['nos', 'set', 'kg', 'litre', 'metre', 'licence', 'service'])],
                ['key' => 'est_unit_price', 'type' => 'decimal', 'label' => 'Estimated unit price (₹)', 'required' => true],
            ]],
            ['key' => 'required_by', 'type' => 'date', 'label' => 'Required by', 'min' => 'today'],
            ['key' => 'delivery_location', 'type' => 'text', 'label' => 'Deliver to'],
            ['key' => 'budgetary_quotes', 'type' => 'files', 'label' => 'Budgetary quotations / brochures', 'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_files' => 10],
        ], [
            $this->reg('record', 'Open purchase case', 'record_indent'),
            $this->authority('hod', 'HoD approval', 'HOD', ['labels' => ['approve' => 'Approve indent']]),
            $this->role('budget', 'verification', 'Budget check and financial concurrence', 'finance_staff', ['labels' => ['approve' => 'Funds available'], 'instructions' => 'Confirm the budget head and concur. Funds are committed when you approve.']),
            $this->reg('commit', 'Commit funds', 'commit_budget'),
            $this->role('sourcing', 'verification', 'Sourcing and comparative statement', 'purchase_officer', ['actions' => ['approve', 'return'], 'labels' => ['approve' => 'Evaluation complete'], 'instructions' => 'In Purchases, record GeM availability / tender reference and the quotations, then select the supplier. The system checks the evaluation against the procurement rules.', 'sla' => ['duration' => 10, 'unit' => 'working_days']]),
            $this->reg('check', 'Check evaluation against the rules', 'check_evaluation'),
            ['committee_route', 'decision', 'Committee required?'],
            ['pc', 'committee', 'Purchase committee', ['committee' => 'PURCHASE_COMMITTEE', 'rule' => 'majority', 'sla' => ['duration' => 5, 'unit' => 'working_days']]],
            ['powers', 'decision', 'Financial powers (example limits)'],
            $this->authority('director', 'Director approval', 'DIRECTOR', ['labels' => ['approve' => 'Approve purchase']]),
            $this->authority('registrar', 'Registrar approval', 'REGISTRAR', ['labels' => ['approve' => 'Approve purchase']]),
            $this->reg('order', 'Issue purchase order', 'issue_order'),
            ...$this->ends(),
        ], [
            ['start', 'record'], ['record', 'hod', 'success'],
            ['hod', 'budget', 'approve'], ['hod', 'refused', 'reject'],
            ['budget', 'commit', 'approve'], ['budget', 'refused', 'reject'],
            ['commit', 'sourcing', 'success'], ['commit', 'refused', 'failure'],
            ['sourcing', 'check', 'approve'],
            ['check', 'committee_route', 'success'], ['check', 'sourcing', 'failure'],
            ['committee_route', 'pc', 'default', ['field' => 'committee_required', 'op' => 'eq', 'value' => true], 10],
            ['committee_route', 'powers', 'default', null, 20],
            ['pc', 'powers', 'approve'], ['pc', 'refused', 'reject'],
            ['powers', 'director', 'default', ['field' => 'estimated_value', 'op' => 'gt', 'value' => 500000], 10],
            ['powers', 'registrar', 'default', null, 20],
            ['director', 'order', 'approve'], ['director', 'refused', 'reject'],
            ['registrar', 'order', 'approve'], ['registrar', 'refused', 'reject'],
            ['order', 'done', 'success'],
        ], 'Purchase: {{ title }}', ['initiators' => ['categories' => ['faculty', 'staff']], 'validator' => 'purchase.indent', 'fallback' => ['type' => 'authority', 'authority' => 'REGISTRAR']]);
    }

    private function receipt(): void
    {
        $this->define('goods-receipt', 'Goods receipt and inspection', 'Record a delivery against a purchase order; the inspecting officer accepts or rejects it.'.self::NOTE, 'procurement', [
            ['key' => 'purchase_order', 'type' => 'select', 'label' => 'Purchase order', 'required' => true, 'source' => 'receivable_orders'],
            ['key' => 'received_on', 'type' => 'date', 'label' => 'Received on', 'required' => true, 'max' => 'today'],
            ['key' => 'received_items', 'type' => 'table', 'label' => 'Received', 'required' => true, 'editable_at' => ['start', 'inspection'], 'max_rows' => 30, 'columns' => [
                ['key' => 'description', 'type' => 'text', 'label' => 'Item (as on the order)', 'required' => true],
                ['key' => 'received', 'type' => 'number', 'label' => 'Received', 'required' => true],
                ['key' => 'accepted', 'type' => 'number', 'label' => 'Accepted', 'required' => true],
            ]],
            ['key' => 'challan', 'type' => 'file', 'label' => 'Delivery challan', 'accept' => ['pdf', 'jpg', 'jpeg', 'png']],
            ['key' => 'inspector', 'type' => 'user', 'label' => 'Inspecting officer', 'required' => true],
            ['key' => 'inspection_remarks', 'type' => 'remark', 'label' => 'Inspection report', 'editable_at' => ['inspection']],
        ], [
            $this->reg('record', 'Record receipt', 'record_receipt'),
            ['inspection', 'verification', 'Inspection', ['assignees' => [['type' => 'field', 'field' => 'inspector']], 'actions' => ['approve', 'reject', 'return'], 'labels' => ['approve' => 'Accept goods'], 'instructions' => 'Check quantity and quality; correct the accepted quantities if needed.', 'sla' => ['duration' => 3, 'unit' => 'working_days']]],
            $this->reg('accept', 'Record acceptance', 'accept_receipt'),
            ...$this->ends(),
        ], [['start', 'record'], ['record', 'inspection', 'success'], ['inspection', 'accept', 'approve'], ['inspection', 'refused', 'reject'], ['accept', 'done', 'success']],
            'Receipt: {{ order_number }}', ['initiators' => ['categories' => ['faculty', 'staff']], 'validator' => 'purchase.receipt', 'fallback' => ['type' => 'role', 'role' => 'purchase_officer']]);
    }

    private function invoice(): void
    {
        $this->define('vendor-invoice', 'Submit an invoice', 'Bill against your purchase order once goods are accepted. Track it and see payment in the vendor portal.'.self::NOTE, 'procurement', [
            ['key' => 'purchase_order', 'type' => 'select', 'label' => 'Purchase order', 'required' => true, 'source' => 'my_billable_orders'],
            ['key' => 'invoice_number', 'type' => 'text', 'label' => 'Invoice number', 'required' => true],
            ['key' => 'invoice_date', 'type' => 'date', 'label' => 'Invoice date', 'required' => true, 'max' => 'today'],
            ['key' => 'invoice_amount', 'type' => 'decimal', 'label' => 'Invoice amount incl. taxes (₹)', 'required' => true, 'min' => 1],
            ['key' => 'invoice_file', 'type' => 'file', 'label' => 'Tax invoice', 'required' => true, 'accept' => ['pdf']],
            ['key' => 'supporting', 'type' => 'files', 'label' => 'Delivery challan / other documents', 'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_files' => 5],
            ['key' => 'deductions', 'type' => 'decimal', 'label' => 'Deductions (TDS, penalties) ₹', 'min' => 0, 'editable_at' => ['verify']],
            ['key' => 'verification_notes', 'type' => 'remark', 'label' => 'Verification notes (internal)', 'editable_at' => ['verify']],
            ['key' => 'payment_reference', 'type' => 'text', 'label' => 'Payment reference (UTR / cheque)', 'required' => true, 'editable_at' => ['payment']],
            ['key' => 'paid_on', 'type' => 'date', 'label' => 'Paid on', 'required' => true, 'editable_at' => ['payment']],
        ], [
            $this->reg('record', 'Register bill', 'record_invoice'),
            $this->role('verify', 'verification', 'Bill verification', 'finance_staff', ['labels' => ['approve' => 'Verified'], 'instructions' => 'Match the invoice with the order and the accepted goods; enter deductions. "Return" sends your remarks to the vendor as a clarification request.']),
            $this->reg('verified', 'Record verification', 'verify_invoice'),
            // No "return" here: a return always goes to the initiator, who is the vendor.
            $this->authority('sanction', 'Payment sanction', 'FINANCE_OFFICER', ['actions' => ['approve', 'reject'], 'labels' => ['approve' => 'Sanction payment']]),
            $this->reg('sanctioned', 'Record sanction', 'sanction_invoice'),
            $this->role('payment', 'verification', 'Payment', 'finance_staff', ['actions' => ['approve'], 'labels' => ['approve' => 'Paid']]),
            $this->reg('pay', 'Record payment and issue advice', 'pay_invoice'),
            ...$this->ends(),
        ], [
            ['start', 'record'], ['record', 'verify', 'success'], ['verify', 'verified', 'approve'], ['verify', 'refused', 'reject'],
            ['verified', 'sanction', 'success'], ['sanction', 'sanctioned', 'approve'], ['sanction', 'refused', 'reject'],
            ['sanctioned', 'payment', 'success'], ['payment', 'pay', 'approve'], ['pay', 'done', 'success'],
        ], 'Bill {{ invoice_number }} ({{ order_number }})', ['initiators' => ['categories' => ['vendor']], 'validator' => 'purchase.invoice', 'fallback' => ['type' => 'role', 'role' => 'finance_staff']]);
    }

    private function advance(): void
    {
        $this->define('advance', 'Advance request', 'An advance for official expenses, to be settled with an expense statement.'.self::NOTE, 'finance', [
            ['key' => 'purpose', 'type' => 'text', 'label' => 'Purpose', 'required' => true],
            ['key' => 'amount_requested', 'type' => 'decimal', 'label' => 'Amount (₹)', 'required' => true, 'min' => 1],
            ['key' => 'settle_by', 'type' => 'date', 'label' => 'Will be settled by', 'required' => true, 'min' => 'today'],
            ['key' => 'budget_head', 'type' => 'select', 'label' => 'Budget head (if known)', 'source' => 'budget_heads'],
            ['key' => 'amount_sanctioned', 'type' => 'decimal', 'label' => 'Amount sanctioned (₹)', 'min' => 0, 'editable_at' => ['sanction']],
            ['key' => 'disbursement_reference', 'type' => 'text', 'label' => 'Disbursement reference', 'required' => true, 'editable_at' => ['disburse']],
        ], [
            $this->reg('record', 'Record advance', 'record_advance'),
            $this->authority('hod', 'HoD recommendation', 'HOD', ['labels' => ['approve' => 'Recommend']]),
            $this->authority('sanction', 'Finance Officer sanction', 'FINANCE_OFFICER', ['labels' => ['approve' => 'Sanction']]),
            $this->reg('sanctioned', 'Record sanction', 'sanction_advance'),
            $this->role('disburse', 'verification', 'Disbursement', 'finance_staff', ['actions' => ['approve'], 'labels' => ['approve' => 'Disbursed']]),
            $this->reg('disbursed', 'Record disbursement', 'disburse_advance'),
            ...$this->ends(),
        ], [
            ['start', 'record'], ['record', 'hod', 'success'], ['hod', 'sanction', 'approve'], ['hod', 'refused', 'reject'],
            ['sanction', 'sanctioned', 'approve'], ['sanction', 'refused', 'reject'], ['sanctioned', 'disburse', 'success'],
            ['disburse', 'disbursed', 'approve'], ['disbursed', 'done', 'success'],
        ], 'Advance: {{ purpose }}', ['initiators' => ['categories' => ['faculty', 'staff']], 'fallback' => ['type' => 'authority', 'authority' => 'FINANCE_OFFICER']]);
    }

    private function settlement(): void
    {
        $this->define('advance-settlement', 'Advance settlement', 'Account for an advance. An unspent balance is refunded; an overspend is reimbursed after sanction.'.self::NOTE, 'finance', [
            ['key' => 'advance', 'type' => 'select', 'label' => 'Advance', 'required' => true, 'source' => 'my_open_advances'],
            ['key' => 'expenses', 'type' => 'table', 'label' => 'Expense statement', 'required' => true, 'max_rows' => 50, 'columns' => [
                ['key' => 'date', 'type' => 'date', 'label' => 'Date', 'required' => true],
                ['key' => 'details', 'type' => 'text', 'label' => 'Details', 'required' => true],
                ['key' => 'amount', 'type' => 'decimal', 'label' => 'Amount (₹)', 'required' => true],
            ]],
            ['key' => 'total', 'type' => 'calculated', 'label' => 'Total spent (₹)', 'formula' => 'sum(expenses.amount)'],
            ['key' => 'receipts', 'type' => 'files', 'label' => 'Receipts', 'required' => true, 'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_files' => 30],
            ['key' => 'refund_reference', 'type' => 'text', 'label' => 'Refund / adjustment reference', 'editable_at' => ['verify']],
        ], [
            $this->role('verify', 'verification', 'Finance verification', 'finance_staff', ['labels' => ['approve' => 'Verified'], 'instructions' => 'Check the statement and receipts. If money is to come back, record the refund reference.']),
            $this->authority('sanction', 'Finance Officer', 'FINANCE_OFFICER', ['labels' => ['approve' => 'Approve settlement']]),
            $this->reg('settle', 'Settle the advance', 'settle_advance'),
            ...$this->ends(),
        ], [['start', 'verify'], ['verify', 'sanction', 'approve'], ['verify', 'refused', 'reject'], ['sanction', 'settle', 'approve'], ['sanction', 'refused', 'reject'], ['settle', 'done', 'success']],
            'Advance settlement', ['initiators' => ['categories' => ['faculty', 'staff']], 'validator' => 'advance.settlement', 'fallback' => ['type' => 'authority', 'authority' => 'FINANCE_OFFICER']]);
    }
}
