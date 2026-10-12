<?php

namespace App\Modules\Finance\Options;

use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;

class FinanceOptions
{
    public function __construct(private string $source) {}

    public function __invoke(?User $user = null): array
    {
        $year = BudgetService::financialYear();

        return match ($this->source) {
            // Heads of the person's department and institute-wide heads, current year.
            'budget_heads' => BudgetHead::query()->where(['is_active' => true, 'financial_year' => $year])
                ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $user?->department_id))->orderBy('code')->get()
                ->map(fn ($h) => ['value' => (string) $h->id, 'label' => "{$h->code} — {$h->name} ({$h->financial_year})"])->all(),
            // Orders awaiting delivery for the person's department (all, for purchase staff).
            'receivable_orders' => PurchaseOrder::query()->with('vendor:id,name')->whereIn('status', ['issued', 'partly_received'])
                ->when(! $user?->can(Permissions::PROCUREMENT_MANAGE), fn ($q) => $q->whereHas('procurementCase', fn ($c) => $c->where('department_id', $user?->department_id)))
                ->latest('issued_at')->get()->map(fn ($o) => ['value' => (string) $o->id, 'label' => "{$o->number} — {$o->vendor->name}"])->all(),
            // A vendor's own orders with accepted goods. How much is still billable
            // is InvoiceValidator's job: it can leave out the bill being resubmitted.
            'my_billable_orders' => ($vendor = $user ? Vendor::forUser($user) : null)
                ? PurchaseOrder::query()->where('vendor_id', $vendor->id)->whereIn('status', ['partly_received', 'received', 'invoiced'])
                    ->whereHas('receipts', fn ($g) => $g->where('status', 'accepted'))->get()
                    ->map(fn ($o) => ['value' => (string) $o->id, 'label' => "{$o->number} (₹".number_format($o->total, 2).')'])->values()->all()
                : [],
            'my_open_advances' => $user ? Advance::query()->where(['user_id' => $user->id, 'status' => 'disbursed'])->get()
                ->map(fn ($a) => ['value' => (string) $a->id, 'label' => '₹'.number_format($a->amount_sanctioned, 2)." — {$a->purpose}"])->all() : [],
            default => [],
        };
    }
}
