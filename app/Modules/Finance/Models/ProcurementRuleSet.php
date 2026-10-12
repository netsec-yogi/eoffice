<?php

namespace App\Modules\Finance\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Procurement method bands. Immutable once published (PROC-002). */
class ProcurementRuleSet extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['bands' => 'array', 'effective_from' => 'date', 'published_at' => 'datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->getOriginal('status') !== 'draft' && $r->isDirty(['bands', 'title', 'authority', 'effective_from'])) {
                throw new LogicException('A published procurement rule set cannot be changed. Draft a new version.');
            }
        });
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public static function current(): ?self
    {
        return self::query()->where('status', 'published')->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->orderByDesc('version')->first();
    }
}
