<?php

namespace App\Modules\Finance\Models;

use App\Contracts\HasDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model implements HasDocuments
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total' => 'float', 'deliver_by' => 'date', 'issued_at' => 'datetime'];
    }

    public function procurementCase(): BelongsTo
    {
        return $this->belongsTo(ProcurementCase::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class);
    }

    /** The vendor, procurement and finance staff, and the indenting department. */
    public function documentsViewableBy(User $user): bool
    {
        if ($user->category === 'vendor') {
            return Vendor::forUser($user)?->id === $this->vendor_id;
        }

        return $user->can(Permissions::PROCUREMENT_MANAGE) || $user->can(Permissions::BUDGET_VIEW)
            || $user->department_id === ProcurementCase::query()->whereKey($this->procurement_case_id)->value('department_id');
    }
}
