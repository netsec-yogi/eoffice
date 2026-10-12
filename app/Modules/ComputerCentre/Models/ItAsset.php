<?php

namespace App\Modules\ComputerCentre\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Descriptive fields are fillable; status and custodian change only through AssetService. */
#[Fillable(['asset_number', 'category', 'manufacturer', 'model', 'serial_number', 'purchase_date', 'purchase_reference', 'cost', 'warranty_until', 'location', 'notes'])]
class ItAsset extends Model
{
    public const STATUSES = ['in_stock', 'issued', 'repair', 'disposed'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'warranty_until' => 'date', 'cost' => 'decimal:2'];
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ItAssetMovement::class)->orderByDesc('id');
    }
}
