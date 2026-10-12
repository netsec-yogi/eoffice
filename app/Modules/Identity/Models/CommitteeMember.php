<?php

namespace App\Modules\Identity\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['committee_id', 'user_id', 'role', 'voting_weight', 'is_voting', 'starts_on', 'ends_on'])]
class CommitteeMember extends Model
{
    public const ROLES = ['chair', 'member', 'secretary', 'convener', 'special_invitee'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_voting' => 'boolean', 'voting_weight' => 'decimal:2'];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCurrent(Builder $query, ?CarbonInterface $on = null): void
    {
        $date = ($on ?? now())->toDateString();
        $query->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }
}
