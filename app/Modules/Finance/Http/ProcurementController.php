<?php

namespace App\Modules\Finance\Http;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\GoodsReceipt;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\Quotation;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\ProcurementRules;
use App\Modules\Finance\Services\ProcurementService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Purchase cases: the register, and the purchase officer's sourcing desk (PROC-001). */
class ProcurementController extends Controller
{
    public function __construct(private ProcurementService $procurement) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $request->validate(['status' => ['nullable', 'string', 'max:24'], 'q' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Procurement/Index', [
            'filters' => $f,
            'register' => $this->seesAll($user),
            'cases' => $this->visible($user)->with(['department:id,code', 'requester:id,name', 'budgetHead:id,code'])
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('number', 'ilike', "%{$t}%")->orWhere('title', 'ilike', "%{$t}%")))
                ->latest()->limit(200)->get()
                ->map(fn (ProcurementCase $c) => [...$c->only('id', 'number', 'title', 'estimated_value', 'committed_amount', 'method', 'status', 'workflow_instance_id'),
                    'department' => $c->department->code, 'requester' => $c->requester->name, 'budget_head' => $c->budgetHead->code, 'method_label' => ProcurementRules::METHODS[$c->method] ?? $c->method, 'created_at' => $c->created_at->toIso8601String()]),
        ]);
    }

    public function show(Request $request, ProcurementCase $case, BudgetService $budget): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user, $case), 403);
        $case->load('department:id,code,name', 'requester:id,name', 'budgetHead', 'orders.vendor:id,code,name', 'orders.document:id,uuid');
        $orderIds = $case->orders->pluck('id');

        return Inertia::render('Procurement/Show', [
            'purchase' => [...$case->only('id', 'number', 'title', 'items', 'estimated_value', 'committed_amount', 'method', 'quotations_required', 'committee_required', 'gem_status', 'gem_reference', 'tender_reference', 'selected_quotation_id', 'status', 'decision', 'workflow_instance_id'),
                'method_label' => ProcurementRules::METHODS[$case->method] ?? $case->method, 'department' => $case->department->name, 'requester' => $case->requester->name,
                'budget_head' => $case->budgetHead->only('id', 'code', 'name', 'financial_year') + ['available' => $user->can(Permissions::BUDGET_VIEW) ? $budget->available($case->budgetHead) : null]],
            'comparative' => $this->procurement->comparative($case),
            'gaps' => in_array($case->status, ['budgeted', 'sourcing'], true) ? $this->procurement->evaluationGaps($case) : [],
            'orders' => $case->orders->map(fn ($o) => [...$o->only('id', 'number', 'total', 'status'), 'document' => $o->document?->uuid, 'vendor' => $o->vendor->name, 'deliver_by' => $o->deliver_by?->toDateString(), 'issued_at' => $o->issued_at->toIso8601String()]),
            'receipts' => GoodsReceipt::query()->whereIn('purchase_order_id', $orderIds)->get()->map(fn ($g) => [...$g->only('id', 'number', 'accepted_value', 'status', 'workflow_instance_id'), 'received_on' => $g->received_on->toDateString()]),
            'invoices' => VendorInvoice::query()->whereIn('purchase_order_id', $orderIds)->get()->map(fn ($i) => [...$i->only('id', 'number', 'invoice_number', 'amount', 'deductions', 'net_payable', 'status', 'payment_reference', 'workflow_instance_id'), 'paid_on' => $i->paid_on?->toDateString()]),
            'canSource' => $user->can(Permissions::PROCUREMENT_MANAGE) && in_array($case->status, ['budgeted', 'sourcing'], true),
            'vendors' => $user->can(Permissions::PROCUREMENT_MANAGE) ? Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($v) => ['value' => $v->id, 'label' => "{$v->name} ({$v->code})"]) : [],
        ]);
    }

    public function sourcing(Request $request, ProcurementCase $case): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        $data = $request->validate([
            'gem_status' => ['required', Rule::in(['available', 'not_available', 'not_applicable'])],
            'gem_reference' => ['nullable', 'string', 'max:255', 'required_if:gem_status,available'],
            'tender_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $this->procurement->recordSourcing($case, $data, $request->user());

        return back()->with('success', 'Sourcing details saved.');
    }

    public function quotation(Request $request, ProcurementCase $case): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999'],
            'technically_qualified' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->procurement->addQuotation($case, Vendor::query()->findOrFail($data['vendor_id']), (float) $data['amount'], $request->boolean('technically_qualified', true), $data['remarks'] ?? null, $request->user());

        return back()->with('success', 'Quotation recorded.');
    }

    public function select(Request $request, ProcurementCase $case): RedirectResponse
    {
        $this->authorize(Permissions::PROCUREMENT_MANAGE);
        $data = $request->validate(['quotation_id' => ['required', 'integer'], 'justification' => ['nullable', 'string', 'max:5000']]);
        $quotation = Quotation::query()->where('procurement_case_id', $case->id)->findOrFail($data['quotation_id']);

        $this->procurement->select($case, $quotation, $data['justification'] ?? null, $request->user());

        return back()->with('success', 'Supplier selected.');
    }

    private function seesAll(User $user): bool
    {
        return $user->can(Permissions::PROCUREMENT_MANAGE) || $user->can(Permissions::BUDGET_VIEW) || $user->can(Permissions::WORKFLOWS_MONITOR);
    }

    private function canView(User $user, ProcurementCase $case): bool
    {
        return $this->seesAll($user) || $case->requested_by === $user->id;
    }

    private function visible(User $user): Builder
    {
        return ProcurementCase::query()->when(! $this->seesAll($user), fn ($q) => $q->where('requested_by', $user->id));
    }
}
