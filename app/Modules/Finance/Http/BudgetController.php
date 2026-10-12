<?php

namespace App\Modules\Finance\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Budget heads and their append-only ledgers (FIN-002). */
class BudgetController extends Controller
{
    public function __construct(private BudgetService $budget) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permissions::BUDGET_VIEW);
        $year = $request->validate(['year' => ['nullable', 'regex:/^\d{4}-\d{2}$/']])['year'] ?? BudgetService::financialYear();

        return Inertia::render('Budget/Index', [
            'year' => $year,
            'years' => BudgetHead::query()->distinct()->orderByDesc('financial_year')->pluck('financial_year')->push(BudgetService::financialYear())->unique()->values(),
            'heads' => BudgetHead::query()->with('department:id,code')->where('financial_year', $year)->orderBy('code')->get()
                ->map(fn (BudgetHead $h) => [...$h->only('id', 'code', 'name', 'financial_year', 'is_active'), 'department' => $h->department?->code, ...$this->budget->position($h)]),
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => "{$d->name} ({$d->code})"]),
            'canManage' => $request->user()->can(Permissions::BUDGET_MANAGE),
        ]);
    }

    public function show(Request $request, BudgetHead $head): Response
    {
        $this->authorize(Permissions::BUDGET_VIEW);

        return Inertia::render('Budget/Show', [
            'head' => [...$head->only('id', 'code', 'name', 'financial_year', 'is_active'), 'department' => $head->department?->name, ...$this->budget->position($head)],
            'entries' => $head->entries()->with('creator:id,name')->orderByDesc('id')->limit(500)->get()
                ->map(fn ($e) => [...$e->only('id', 'kind', 'amount', 'description', 'reference_type', 'reference_id'), 'by' => $e->creator?->name, 'at' => $e->created_at->toIso8601String()]),
            'canManage' => $request->user()->can(Permissions::BUDGET_MANAGE),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize(Permissions::BUDGET_MANAGE);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('budget_heads')->where('financial_year', $request->input('financial_year'))],
            'name' => ['required', 'string', 'max:255'],
            'financial_year' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);
        $head = BudgetHead::query()->create($data + ['is_active' => true]);
        $audit->record('budget.head_created', $head, $data, actor: $request->user());

        return back()->with('success', "Budget head {$head->code} added.");
    }

    /** Allocation or re-appropriation; corrections are new entries, never edits. */
    public function post(Request $request, BudgetHead $head): RedirectResponse
    {
        $this->authorize(Permissions::BUDGET_MANAGE);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['allocation', 'reappropriation'])],
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-9999999999999,9999999999999'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        if ((float) $data['amount'] < 0 && $this->budget->available($head) + (float) $data['amount'] < 0) {
            return back()->withErrors(['amount' => 'This would take the head below what is already committed or spent.']);
        }
        $this->budget->post($head, $data['kind'], (float) $data['amount'], $data['description'], null, $request->user());

        return back()->with('success', 'Entry posted.');
    }
}
