<?php

namespace App\Modules\Finance\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only budget ledger; balances are sums. */
class BudgetEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Budget entries are never changed; post a correcting entry.'));
        static::deleting(fn () => throw new LogicException('Budget entries are never deleted; post a correcting entry.'));
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(BudgetHead::class, 'budget_head_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
