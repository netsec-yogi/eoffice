<?php

namespace App\Modules\Identity\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['authority_id', 'user_id', 'department_id', 'starts_on', 'ends_on', 'is_acting', 'order_reference'])]
class AuthorityAssignment extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_acting' => 'boolean'];
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(Authority::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Assignments in force on the given date (default: today). */
    public function scopeCurrent(Builder $query, ?CarbonInterface $on = null): void
    {
        $date = ($on ?? now())->toDateString();
        $query->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }
}
