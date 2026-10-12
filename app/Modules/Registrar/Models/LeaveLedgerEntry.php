<?php

namespace App\Modules\Registrar\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only. Balances are sums of these rows. */
class LeaveLedgerEntry extends Model
{
    protected $table = 'leave_ledger';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['days' => 'float'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Leave ledger entries are never changed; post an adjustment.'));
        static::deleting(fn () => throw new LogicException('Leave ledger entries are never deleted; post an adjustment.'));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
