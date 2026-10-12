<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #111; margin: 40px 44px; font-size: 11px; line-height: 1.5; }
    .head { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 16px; }
    .inst { font-size: 16px; font-weight: bold; } h1 { font-size: 14px; text-align: center; margin: 4px 0 14px; }
    .meta { width: 100%; margin-bottom: 14px; } .meta td { vertical-align: top; padding: 2px 0; }
    table.items { width: 100%; border-collapse: collapse; margin: 10px 0; } table.items th, table.items td { border: 1px solid #999; padding: 4px 6px; }
    table.items th { background: #eef2ff; text-align: left; } .r { text-align: right; }
    .foot { position: fixed; bottom: 26px; left: 44px; right: 44px; font-size: 9px; color: #555; border-top: 1px solid #ccc; padding-top: 5px; }
</style></head>
<body>
    <div class="head"><div class="inst">{{ $institute }}</div></div>
    <h1>PURCHASE ORDER</h1>
    <table class="meta"><tr>
        <td><strong>To</strong><br>{{ $order->vendor->name }}<br>{{ $order->vendor->address }}@if ($order->vendor->gstin)<br>GSTIN {{ $order->vendor->gstin }}@endif</td>
        <td class="r">No. <strong>{{ $order->number }}</strong><br>Date {{ $order->issued_at->format('d M Y') }}<br>Reference {{ $case->number }}<br>Deliver by {{ $order->deliver_by?->format('d M Y') }}</td>
    </tr></table>
    <p>Please supply the following, as per your quotation, for: <strong>{{ $case->title }}</strong></p>
    <table class="items">
        <thead><tr><th>#</th><th>Item and specification</th><th class="r">Qty</th><th>Unit</th><th class="r">Unit price (₹)</th><th class="r">Amount (₹)</th></tr></thead>
        <tbody>
        @foreach ($order->items as $i => $item)
            <tr><td>{{ $i + 1 }}</td><td>{{ $item['description'] }}@if (! empty($item['specification']))<br><small>{{ $item['specification'] }}</small>@endif</td><td class="r">{{ $item['qty'] }}</td><td>{{ $item['unit'] ?? '' }}</td><td class="r">{{ number_format($item['unit_price'], 2) }}</td><td class="r">{{ number_format($item['unit_price'] * $item['qty'], 2) }}</td></tr>
        @endforeach
        <tr><td colspan="5" class="r"><strong>Total (as quoted, incl. taxes)</strong></td><td class="r"><strong>{{ number_format($order->total, 2) }}</strong></td></tr>
        </tbody>
    </table>
    <p><strong>Terms.</strong> {{ $order->terms }}</p>
    <p>Procurement method: {{ $case->decision['label'] ?? $case->method }} (rule set version {{ $case->decision['rule_version'] ?? '—' }}).</p>
    <div class="foot">Issued through the institute workflow system after approval. Not digitally signed. Quote the order number on the invoice and delivery challan.</div>
</body></html>
