<?php

namespace App\Modules\Identity\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time-bounded transfer of approval work from a delegator to a delegate.
 * Status and approval/revocation fields are set by DelegationService only.
 */
#[Fillable(['delegator_id', 'delegate_id', 'starts_at', 'ends_at', 'workflow_categories', 'department_ids', 'allowed_actions', 'reason'])]
class Delegation extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
            'workflow_categories' => 'array',
            'department_ids' => 'array',
            'allowed_actions' => 'array',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function scopeInForce(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $query->where('status', 'active')->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    /** Does this delegation cover work of this category, department and action? */
    public function covers(?string $category, ?int $departmentId, string $action): bool
    {
        return ($this->workflow_categories === null || in_array($category, $this->workflow_categories, true))
            && ($this->department_ids === null || in_array($departmentId, $this->department_ids, true))
            && ($this->allowed_actions === null || in_array($action, $this->allowed_actions, true));
    }
}
