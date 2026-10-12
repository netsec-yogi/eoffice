<?php

namespace App\Modules\Finance\Validators;

use App\Modules\Finance\Models\GoodsReceipt;
use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;

/**
 * Three-way match before a bill enters verification: the order is the
 * vendor's own, goods have been accepted, and the bill does not exceed the
 * accepted value not yet billed. Duplicate invoice numbers are refused.
 */
class InvoiceValidator implements SubmissionValidator
{
    public function label(): string
    {
        return 'Purchase: invoice matches order and accepted goods';
    }

    public function provides(): array
    {
        return ['order_number', 'billable'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $vendor = Vendor::forUser($initiator);
        $order = PurchaseOrder::query()->find($data['purchase_order'] ?? 0);
        $ownOrder = $order && ($vendor ? $order->vendor_id === $vendor->id : $initiator->can(Permissions::PROCUREMENT_MANAGE));
        if (! $ownOrder) {
            throw ValidationException::withMessages(['purchase_order' => 'Choose one of your purchase orders.']);
        }
        $duplicate = VendorInvoice::query()->where(['vendor_id' => $order->vendor_id, 'invoice_number' => $data['invoice_number']])->where('workflow_instance_id', '!=', $instance->id)->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['invoice_number' => 'This invoice number has already been submitted.']);
        }
        $accepted = (float) GoodsReceipt::query()->where('purchase_order_id', $order->id)->where('status', 'accepted')->sum('accepted_value');
        $billed = (float) VendorInvoice::query()->where('purchase_order_id', $order->id)->whereNotIn('status', ['rejected'])->where('workflow_instance_id', '!=', $instance->id)->sum('amount');
        $billable = round($accepted - $billed, 2);
        if ($accepted <= 0) {
            throw ValidationException::withMessages(['purchase_order' => 'No goods have been received and accepted against this order yet.']);
        }
        if ((float) $data['invoice_amount'] > $billable + 0.01) {
            throw ValidationException::withMessages(['invoice_amount' => 'The amount exceeds the accepted goods not yet billed (₹'.number_format($billable, 2).').']);
        }

        return ['order_number' => $order->number, 'billable' => $billable];
    }
}
