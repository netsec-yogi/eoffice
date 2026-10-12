<?php

namespace App\Modules\Finance\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Finance\Models\BudgetEntry;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Models\GoodsReceipt;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Finance\Models\Quotation;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Support\SequenceGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The procurement register behind the indent, goods-receipt and invoice
 * workflows (PROC-001). Approvals happen in the engine; this service keeps
 * the case, budget, orders, receipts and bills consistent. Every method
 * that a workflow step calls is idempotent.
 */
class ProcurementService
{
    public function __construct(
        private BudgetService $budget,
        private SequenceGenerator $sequences,
        private DocumentStore $documents,
        private NotificationDispatcher $notifications,
        private AuditLogger $audit,
    ) {}

    // ---- Indent ------------------------------------------------------------

    public function recordIndent(WorkflowInstance $instance): ProcurementCase
    {
        $case = ProcurementCase::query()->where('workflow_instance_id', $instance->id)->first();
        $d = $instance->data;
        $decision = $d['procurement_decision'];
        $attributes = [
            'title' => $d['title'], 'items' => $d['items'], 'estimated_value' => (float) $d['estimated_value'],
            'budget_head_id' => (int) $d['budget_head'], 'procurement_rule_set_id' => $decision['rule_set_id'], 'decision' => $decision,
            'method' => $decision['method'], 'quotations_required' => $decision['quotations'], 'committee_required' => $decision['committee'],
        ];
        if ($case) {
            if ($case->status === 'indent') {
                $case->forceFill($attributes)->save(); // resubmitted indent
            }

            return $case;
        }
        $case = new ProcurementCase;
        $case->forceFill($attributes + [
            'number' => $this->sequences->format('PROC', 'PR/{YYYY}/{SEQ:5}'),
            'workflow_instance_id' => $instance->id, 'department_id' => $instance->department_id, 'requested_by' => $instance->initiator_id, 'status' => 'indent',
        ])->save();
        $this->audit->record('procurement.indent', $case, ['value' => $case->estimated_value, 'decision' => $decision], actor: null);

        return $case;
    }

    public function commitBudget(ProcurementCase $case): void
    {
        if ($this->budget->posted($case, 'commitment')) {
            return;
        }
        $this->budget->commit(BudgetHead::query()->findOrFail($case->budget_head_id), $case->estimated_value, "Indent {$case->number}: {$case->title}", $case);
        $case->forceFill(['committed_amount' => $case->estimated_value, 'status' => 'budgeted'])->save();
    }

    // ---- Sourcing and evaluation -------------------------------------------

    public function recordSourcing(ProcurementCase $case, array $data, User $by): void
    {
        $this->sourcing($case);
        $case->forceFill([
            'gem_status' => $data['gem_status'] ?? $case->gem_status, 'gem_reference' => $data['gem_reference'] ?? null,
            'tender_reference' => $data['tender_reference'] ?? null, 'status' => 'sourcing',
        ])->save();
        $this->audit->record('procurement.sourcing', $case, $data, actor: $by);
    }

    public function addQuotation(ProcurementCase $case, Vendor $vendor, float $amount, bool $qualified, ?string $remarks, User $by): Quotation
    {
        $this->sourcing($case);
        if (! $vendor->is_active) {
            throw new WorkflowException('This vendor is not active.', 422, ['vendor_id' => 'Inactive vendor.']);
        }
        $q = Quotation::query()->where(['procurement_case_id' => $case->id, 'vendor_id' => $vendor->id])->first() ?? (new Quotation)->forceFill(['procurement_case_id' => $case->id, 'vendor_id' => $vendor->id]);
        $q->forceFill(['amount' => round($amount, 2), 'technically_qualified' => $qualified, 'remarks' => $remarks, 'recorded_by' => $by->id])->save();
        $case->forceFill(['status' => 'sourcing', 'selected_quotation_id' => null])->save(); // a changed field invalidates an earlier selection
        $this->audit->record('procurement.quotation', $case, ['vendor' => $vendor->code, 'amount' => $amount, 'qualified' => $qualified], actor: $by);

        return $q;
    }

    /** Comparative statement: technically qualified quotations, lowest first. */
    public function comparative(ProcurementCase $case): array
    {
        $rows = $case->quotations()->with('vendor:id,code,name')->get()->sortBy([['technically_qualified', 'desc'], ['amount', 'asc']])->values();
        $rank = 0;

        return $rows->map(function (Quotation $q) use (&$rank) {
            return ['id' => $q->id, 'vendor' => $q->vendor->name, 'vendor_code' => $q->vendor->code, 'amount' => $q->amount, 'qualified' => $q->technically_qualified, 'remarks' => $q->remarks, 'rank' => $q->technically_qualified ? 'L'.(++$rank) : '—'];
        })->all();
    }

    public function select(ProcurementCase $case, Quotation $quotation, ?string $justification, User $by): void
    {
        $this->sourcing($case);
        if ($quotation->procurement_case_id !== $case->id || ! $quotation->technically_qualified) {
            throw new WorkflowException('Select a technically qualified quotation of this case.', 422);
        }
        $l1 = collect($this->comparative($case))->firstWhere('rank', 'L1');
        if ($l1 && $l1['id'] !== $quotation->id && trim((string) $justification) === '') {
            throw new WorkflowException('This is not the lowest qualified quotation (L1). Record the justification.', 422, ['justification' => 'Required when not selecting L1.']);
        }
        $case->forceFill(['selected_quotation_id' => $quotation->id])->save();
        $this->audit->record('procurement.selected', $case, ['quotation' => $quotation->id, 'amount' => $quotation->amount, 'l1' => $l1['id'] ?? null, 'justification' => $justification], actor: $by);
    }

    /** What still stops the evaluation from being complete (empty = complete). */
    public function evaluationGaps(ProcurementCase $case): array
    {
        $gaps = [];
        $qualified = $case->quotations()->where('technically_qualified', true)->count();
        if ($qualified < $case->quotations_required) {
            $gaps[] = "{$case->quotations_required} qualified quotation(s) needed under rule version {$case->decision['rule_version']} ({$case->decision['label']}); {$qualified} recorded.";
        }
        if ($case->method !== 'direct' && ! $case->gem_status) {
            $gaps[] = 'Record whether the item is available on GeM.';
        }
        if (in_array($case->method, ['limited_tender', 'open_tender'], true) && ! $case->tender_reference) {
            $gaps[] = 'Record the tender reference.';
        }
        if (! $case->selected_quotation_id && ($case->method !== 'direct' || $case->quotations()->exists())) {
            $gaps[] = 'Select the supplier from the comparative statement.';
        }
        if ($case->method === 'direct' && ! $case->quotations()->exists()) {
            $gaps[] = 'Record the supplier and the price.';
        }

        return $gaps;
    }

    // ---- Order ---------------------------------------------------------------

    public function issueOrder(ProcurementCase $case, ?User $by): PurchaseOrder
    {
        if ($existing = $case->orders()->where('status', '!=', 'cancelled')->first()) {
            return $existing;
        }

        $quotation = Quotation::query()->with('vendor')->findOrFail($case->selected_quotation_id ?? $case->quotations()->value('id'));
        $order = DB::transaction(function () use ($case, $quotation, $by) {
            // The commitment follows the actual order value.
            $head = BudgetHead::query()->findOrFail($case->budget_head_id);
            $difference = round($quotation->amount - $case->committed_amount, 2);
            if ($difference > 0) {
                $this->budget->commit($head, $difference, "Order value above estimate ({$case->number})", $case, $by);
            } elseif ($difference < 0) {
                $this->budget->post($head, 'commitment_release', -$difference, "Order value below estimate ({$case->number})", $case, $by);
            }

            $factor = $case->estimated_value > 0 ? $quotation->amount / $case->estimated_value : 1;
            $order = new PurchaseOrder;
            $order->forceFill([
                'number' => $this->sequences->format('PO', 'PO/{YYYY}/{SEQ:5}'),
                'procurement_case_id' => $case->id, 'vendor_id' => $quotation->vendor_id,
                'items' => array_map(fn ($i) => $i + ['unit_price' => round((float) $i['est_unit_price'] * $factor, 2)], $case->items),
                'total' => $quotation->amount, 'deliver_by' => now()->addDays((int) config('finance.default_delivery_days', 30))->toDateString(),
                'terms' => config('finance.po_terms'), 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $by?->id,
            ])->save();
            $case->forceFill(['committed_amount' => $quotation->amount, 'status' => 'ordered'])->save();

            $pdf = Pdf::loadView('pdf.purchase-order', ['order' => $order->load('vendor'), 'case' => $case, 'institute' => config('app.name')])->output();
            $path = tempnam(sys_get_temp_dir(), 'po');
            file_put_contents($path, $pdf);
            $doc = $this->documents->store(new UploadedFile($path, Str::slug($order->number).'.pdf', 'application/pdf', null, true), $order, $by ?? User::query()->findOrFail($case->requested_by), 'purchase_order', 'internal');
            $order->forceFill(['document_id' => $doc->id])->save();
            $this->audit->record('procurement.po_issued', $order, ['case' => $case->number, 'vendor' => $quotation->vendor->code, 'total' => $order->total], actor: $by);

            return $order;
        });

        if ($vendorUser = $quotation->vendor->user) {
            $this->notify('po.issued', $vendorUser, ['order' => ['number' => $order->number, 'total' => number_format($order->total, 2)]], "po:{$order->id}", route('vendor.portal', [], false));
        }

        return $order;
    }

    // ---- Receipt ---------------------------------------------------------------

    public function recordReceipt(WorkflowInstance $instance, bool $accept): GoodsReceipt
    {
        $d = $instance->data;
        $order = PurchaseOrder::query()->findOrFail($d['purchase_order']);
        $grn = GoodsReceipt::query()->where('workflow_instance_id', $instance->id)->first()
            ?? (new GoodsReceipt)->forceFill(['workflow_instance_id' => $instance->id, 'number' => $this->sequences->format('GRN', 'GRN/{YYYY}/{SEQ:5}'), 'purchase_order_id' => $order->id]);
        $prices = collect($order->items)->keyBy('description');
        $items = array_map(fn ($r) => $r + ['unit_price' => (float) ($prices[$r['description']]['unit_price'] ?? 0)], $d['received_items'] ?? []);
        $grn->forceFill([
            'received_on' => $d['received_on'], 'items' => $items, 'inspection_remarks' => $d['inspection_remarks'] ?? null,
            'accepted_value' => round(array_sum(array_map(fn ($r) => (float) ($r['accepted'] ?? 0) * $r['unit_price'], $items)), 2),
        ]);
        if ($accept && $grn->status !== 'accepted') {
            $grn->status = 'accepted';
            $grn->save();
            $acceptedTotal = (float) GoodsReceipt::query()->where('purchase_order_id', $order->id)->where('status', 'accepted')->sum('accepted_value');
            $order->forceFill(['status' => $acceptedTotal + 0.01 >= $order->total ? 'received' : 'partly_received'])->save();
            ProcurementCase::query()->whereKey($order->procurement_case_id)->update(['status' => 'received', 'updated_at' => now()]);
            $this->audit->record('procurement.goods_accepted', $order, ['grn' => $grn->number, 'value' => $grn->accepted_value], actor: null);
        } else {
            $grn->save();
        }

        return $grn;
    }

    // ---- Invoice and payment ------------------------------------------------------

    public function recordInvoice(WorkflowInstance $instance): VendorInvoice
    {
        $d = $instance->data;
        $order = PurchaseOrder::query()->findOrFail($d['purchase_order']);
        $invoice = VendorInvoice::query()->where('workflow_instance_id', $instance->id)->first()
            ?? (new VendorInvoice)->forceFill(['workflow_instance_id' => $instance->id, 'number' => $this->sequences->format('BILL', 'BILL/{YYYY}/{SEQ:5}'), 'purchase_order_id' => $order->id, 'vendor_id' => $order->vendor_id]);
        if (in_array($invoice->status, ['paid', 'rejected'], true)) {
            return $invoice;
        }
        $invoice->forceFill([
            'invoice_number' => $d['invoice_number'], 'invoice_date' => $d['invoice_date'], 'amount' => (float) $d['invoice_amount'],
            'deductions' => (float) ($d['deductions'] ?? 0), 'net_payable' => round((float) $d['invoice_amount'] - (float) ($d['deductions'] ?? 0), 2),
            'status' => $invoice->exists ? $invoice->status : 'submitted', 'clarification' => null,
        ])->save();
        if ($order->status === 'received') {
            $order->forceFill(['status' => 'invoiced'])->save();
        }

        return $invoice;
    }

    public function markInvoice(WorkflowInstance $instance, string $status): VendorInvoice
    {
        $invoice = VendorInvoice::query()->where('workflow_instance_id', $instance->id)->firstOrFail();
        $d = $instance->data;
        if ($status === 'verified') {
            $deductions = (float) ($d['deductions'] ?? 0);
            $invoice->forceFill(['deductions' => $deductions, 'net_payable' => round($invoice->amount - $deductions, 2)]);
        }
        if (! in_array($invoice->status, ['paid', 'rejected'], true)) {
            $invoice->forceFill(['status' => $status])->save();
        }

        return $invoice;
    }

    public function pay(WorkflowInstance $instance): VendorInvoice
    {
        $invoice = VendorInvoice::query()->with('order.procurementCase', 'vendor.user')->where('workflow_instance_id', $instance->id)->firstOrFail();
        if ($invoice->status === 'paid') {
            return $invoice;
        }
        $d = $instance->data;
        DB::transaction(function () use ($invoice, $d) {
            $case = $invoice->order->procurementCase;
            $head = BudgetHead::query()->findOrFail($case->budget_head_id);
            $this->budget->post($head, 'commitment_release', $invoice->amount, "Bill {$invoice->number} ({$invoice->invoice_number})", $invoice);
            $this->budget->post($head, 'expenditure', -$invoice->amount, "Paid bill {$invoice->number} to {$invoice->vendor->name}", $invoice);
            $invoice->forceFill(['status' => 'paid', 'payment_reference' => $d['payment_reference'] ?? null, 'paid_on' => $d['paid_on'] ?? today()->toDateString()])->save();

            $pdf = Pdf::loadView('pdf.payment-advice', ['invoice' => $invoice, 'institute' => config('app.name')])->output();
            $path = tempnam(sys_get_temp_dir(), 'pa');
            file_put_contents($path, $pdf);
            $doc = $this->documents->store(new UploadedFile($path, Str::slug('payment-advice-'.$invoice->number).'.pdf', 'application/pdf', null, true), $invoice, User::query()->findOrFail($case->requested_by), 'payment_advice', 'internal');
            $invoice->forceFill(['payment_advice_id' => $doc->id])->save();

            // Fully paid order: close it and give back whatever is still committed.
            $order = $invoice->order;
            $paid = (float) VendorInvoice::query()->where('purchase_order_id', $order->id)->where('status', 'paid')->sum('amount');
            if ($paid + 0.01 >= $order->total) {
                $order->forceFill(['status' => 'closed'])->save();
                $this->close($case);
            }
            $this->audit->record('procurement.paid', $invoice, ['amount' => $invoice->amount, 'net' => $invoice->net_payable, 'reference' => $invoice->payment_reference], actor: null);
        });

        if ($vendorUser = $invoice->vendor->user) {
            $this->notify('invoice.paid', $vendorUser, ['invoice' => ['number' => $invoice->invoice_number, 'net' => number_format($invoice->net_payable, 2), 'reference' => $invoice->payment_reference]], "invoice:{$invoice->id}:paid", route('vendor.portal', [], false));
        }

        return $invoice;
    }

    /** Release what is still committed and close the case. */
    public function close(ProcurementCase $case, string $status = 'closed'): void
    {
        $head = BudgetHead::query()->findOrFail($case->budget_head_id);
        $remaining = -1 * (float) BudgetEntry::query()
            ->where(fn ($q) => $q->where(['reference_type' => 'procurement_case', 'reference_id' => $case->id])
                ->orWhere(fn ($q) => $q->where('reference_type', 'vendor_invoice')->whereIn('reference_id', VendorInvoice::query()->whereIn('purchase_order_id', $case->orders()->pluck('id'))->pluck('id'))))
            ->whereIn('kind', ['commitment', 'commitment_release'])->sum('amount');
        if ($remaining > 0.004) {
            $this->budget->post($head, 'commitment_release', $remaining, "Case {$case->number} {$status}: unused commitment released", $case);
        }
        $case->forceFill(['status' => $status])->save();
    }

    private function sourcing(ProcurementCase $case): void
    {
        if (! in_array($case->status, ['budgeted', 'sourcing'], true)) {
            throw new WorkflowException('Quotations can be recorded only after the budget check and before the order.', 409);
        }
    }

    private function notify(string $template, User $to, array $context, string $key, string $link): void
    {
        try {
            $this->notifications->send($template, [$to], $context, $key, $link);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
