<?php

namespace App\Modules\Finance\Models;

use App\Contracts\HasDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorInvoice extends Model implements HasDocuments
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'paid_on' => 'date', 'amount' => 'float', 'deductions' => 'float', 'net_payable' => 'float'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function paymentAdvice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'payment_advice_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function documentsViewableBy(User $user): bool
    {
        if ($user->category === 'vendor') {
            return Vendor::forUser($user)?->id === $this->vendor_id;
        }

        return $user->can(Permissions::BUDGET_VIEW) || $user->can(Permissions::PROCUREMENT_MANAGE);
    }
}
