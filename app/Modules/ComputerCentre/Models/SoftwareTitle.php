<?php

namespace App\Modules\ComputerCentre\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'publisher', 'licence_type', 'seats', 'expires_on', 'agreement_reference', 'is_active'])]
class SoftwareTitle extends Model
{
    protected function casts(): array
    {
        return ['expires_on' => 'date', 'is_active' => 'boolean', 'seats' => 'integer'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LicenceAllocation::class);
    }

    public function activeAllocations(): HasMany
    {
        return $this->allocations()->whereNull('revoked_at');
    }
}
