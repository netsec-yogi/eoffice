<?php

namespace App\Modules\Finance\Http;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Workflow\Models\WorkflowAction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a vendor sees (VEND-002): their own orders, their bills and their
 * payments. Nothing internal: no notes, no approvers, no other vendors.
 * The one internal text that reaches a vendor is the clarification an
 * officer deliberately writes when returning the bill.
 */
class VendorPortalController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $vendor = Vendor::forUser($request->user());
        abort_unless($vendor, 403, 'This account is not linked to a vendor.');

        $invoices = VendorInvoice::query()->with('order:id,number', 'instance:id,status', 'paymentAdvice:id,uuid')->where('vendor_id', $vendor->id)->latest()->limit(200)->get();
        $clarifications = WorkflowAction::query()->whereIn('workflow_instance_id', $invoices->filter(fn ($i) => $i->instance?->status === 'returned')->pluck('workflow_instance_id'))
            ->where('action', 'returned')->orderByDesc('id')->get()->unique('workflow_instance_id')->pluck('remarks', 'workflow_instance_id');

        return Inertia::render('VendorPortal/Index', [
            'vendor' => $vendor->only('code', 'name', 'gstin', 'email') + ['account' => $vendor->maskedAccount()],
            'orders' => PurchaseOrder::query()->with('document:id,uuid')->where('vendor_id', $vendor->id)->where('status', '!=', 'cancelled')->latest('issued_at')->limit(200)->get()
                ->map(fn (PurchaseOrder $o) => [...$o->only('id', 'number', 'total', 'status'),
                    // Only what is on the order: never the indent's internal estimates.
                    'items' => array_map(fn ($i) => array_intersect_key($i, array_flip(['description', 'specification', 'qty', 'unit', 'unit_price'])), $o->items), 'document' => $o->document?->uuid, 'deliver_by' => $o->deliver_by?->toDateString(), 'issued_at' => $o->issued_at->toDateString()]),
            'invoices' => $invoices->map(function (VendorInvoice $i) use ($clarifications) {
                $returned = $i->instance?->status === 'returned';
                $paid = $i->status === 'paid';

                return [
                    ...$i->only('id', 'number', 'invoice_number', 'amount'), 'order' => $i->order->number, 'invoice_date' => $i->invoice_date->toDateString(),
                    'status' => $returned ? 'clarification' : $i->status,
                    'clarification' => $returned ? $clarifications[$i->workflow_instance_id] ?? null : null,
                    'edit_instance_id' => $returned ? $i->workflow_instance_id : null,
                    // Deductions and the net amount are shown once paid, on the advice.
                    'deductions' => $paid ? $i->deductions : null, 'net_payable' => $paid ? $i->net_payable : null,
                    'payment_reference' => $paid ? $i->payment_reference : null, 'paid_on' => $paid ? $i->paid_on?->toDateString() : null,
                    'payment_advice' => $paid ? $i->paymentAdvice?->uuid : null,
                ];
            }),
            'canBill' => PurchaseOrder::query()->where('vendor_id', $vendor->id)->whereIn('status', ['received', 'partly_received', 'invoiced'])->exists(),
        ]);
    }
}
