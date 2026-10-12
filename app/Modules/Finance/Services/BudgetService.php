<?php

namespace App\Modules\Finance\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\BudgetEntry;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Budget heads and their ledger (FIN-002). Commitments are taken with the
 * head row locked, so two indents cannot both spend the last rupee.
 */
class BudgetService
{
    public function __construct(private AuditLogger $audit) {}

    public static function financialYear(?\DateTimeInterface $on = null): string
    {
        $d = CarbonImmutable::instance($on ?? now());
        $startMonth = (int) config('finance.year_starts_month', 4);
        $start = $d->month >= $startMonth ? $d->year : $d->year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    /** @return array{allocated: float, committed: float, spent: float, available: float} */
    public function position(BudgetHead $head): array
    {
        $sum = fn (array $kinds) => (float) BudgetEntry::query()->where('budget_head_id', $head->id)->whereIn('kind', $kinds)->sum('amount');
        $allocated = $sum(['allocation', 'reappropriation']);
        // 0.0 - x, not -x: an empty sum would otherwise show as "-0.00".
        $committed = 0.0 - $sum(['commitment', 'commitment_release']);
        $spent = 0.0 - $sum(['expenditure']);

        return ['allocated' => $allocated, 'committed' => round($committed, 2), 'spent' => round($spent, 2), 'available' => round($allocated - $committed - $spent, 2)];
    }

    public function available(BudgetHead $head): float
    {
        return $this->position($head)['available'];
    }

    public function post(BudgetHead $head, string $kind, float $amount, string $description, ?Model $reference = null, ?User $by = null): BudgetEntry
    {
        $entry = new BudgetEntry;
        $entry->forceFill([
            'budget_head_id' => $head->id, 'kind' => $kind, 'amount' => round($amount, 2), 'description' => mb_substr($description, 0, 255),
            'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(), 'created_by' => $by?->id, 'created_at' => now(),
        ])->save();
        $this->audit->record("budget.{$kind}", $head, ['amount' => $amount, 'description' => $description, 'reference' => $reference ? $reference->getMorphClass().':'.$reference->getKey() : null], actor: $by);

        return $entry;
    }

    /** Reserve funds; throws when the head cannot cover it. */
    public function commit(BudgetHead $head, float $amount, string $description, Model $reference, ?User $by = null): void
    {
        DB::transaction(function () use ($head, $amount, $description, $reference, $by) {
            $head = BudgetHead::query()->lockForUpdate()->findOrFail($head->id);
            if ($this->available($head) < $amount) {
                throw new WorkflowException("Budget head {$head->code} has ₹".number_format($this->available($head), 2).' available; ₹'.number_format($amount, 2).' is needed.', 422);
            }
            $this->post($head, 'commitment', -$amount, $description, $reference, $by);
        });
    }

    /** Has $reference already posted an entry of this kind (idempotent steps)? */
    public function posted(Model $reference, string $kind): bool
    {
        return BudgetEntry::query()->where(['reference_type' => $reference->getMorphClass(), 'reference_id' => $reference->getKey(), 'kind' => $kind])->exists();
    }
}
