<?php

namespace App\Modules\Finance\Models;

use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementCase extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['items' => 'array', 'decision' => 'array', 'estimated_value' => 'float', 'committed_amount' => 'float', 'committee_required' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function budgetHead(): BelongsTo
    {
        return $this->belongsTo(BudgetHead::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function selected(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'selected_quotation_id');
    }
}
