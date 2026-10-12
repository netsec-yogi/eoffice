<?php

namespace App\Modules\Finance\Validators;

use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Forms\SubmissionValidator;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;

/** Goods receipt against an open order: quantities cannot exceed what was ordered. */
class GoodsReceiptValidator implements SubmissionValidator
{
    public function label(): string
    {
        return 'Purchase: goods received against the order';
    }

    public function provides(): array
    {
        return ['order_number'];
    }

    public function validate(array $data, User $initiator, WorkflowInstance $instance): array
    {
        $order = PurchaseOrder::query()->whereIn('status', ['issued', 'partly_received'])->find($data['purchase_order'] ?? 0)
            ?? throw ValidationException::withMessages(['purchase_order' => 'Choose an order awaiting delivery.']);
        $ordered = collect($order->items)->keyBy('description');
        foreach ($data['received_items'] ?? [] as $i => $row) {
            $line = $ordered[$row['description'] ?? ''] ?? null;
            if (! $line) {
                throw ValidationException::withMessages(["received_items.{$i}.description" => 'Not an item of this order.']);
            }
            if ((float) ($row['received'] ?? 0) > (float) $line['qty'] || (float) ($row['accepted'] ?? 0) > (float) ($row['received'] ?? 0)) {
                throw ValidationException::withMessages(["received_items.{$i}.received" => 'Received cannot exceed ordered, nor accepted exceed received.']);
            }
        }

        return ['order_number' => $order->number];
    }
}
