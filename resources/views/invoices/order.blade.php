<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; padding: 32px; color: #0f172a; background: #fff; }
        .sheet { max-width: 720px; margin: 0 auto; }
        .row { display: flex; justify-content: space-between; gap: 24px; }
        .muted { color: #64748b; font-size: 14px; }
        h1 { margin: 0 0 4px; font-size: 28px; }
        h2 { margin: 28px 0 12px; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { color: #64748b; font-weight: 600; }
        .totals { margin-top: 16px; width: 280px; margin-left: auto; }
        .totals div { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
        .totals .grand { font-size: 18px; font-weight: 700; border-top: 1px solid #e2e8f0; margin-top: 8px; padding-top: 12px; }
        .actions { margin: 0 0 24px; display: flex; gap: 8px; }
        .actions button, .actions a {
            appearance: none; border: 1px solid #cbd5e1; background: #fff; border-radius: 8px;
            padding: 8px 12px; font-size: 13px; font-weight: 600; color: #0f172a; text-decoration: none; cursor: pointer;
        }
        @media print {
            .actions { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="row">
        <div>
            <h1>{{ $store->name }}</h1>
            <div class="muted">
                @if($store->contact_email) {{ $store->contact_email }}<br> @endif
                @if($store->contact_phone) {{ $store->contact_phone }} @endif
            </div>
        </div>
        <div style="text-align:right">
            <div class="muted">Invoice</div>
            <strong>{{ $invoiceNumber }}</strong>
            <div class="muted" style="margin-top:8px">Order {{ $order->order_number }}</div>
            <div class="muted">{{ optional($order->placed_at)->format('d M Y, H:i') }}</div>
        </div>
    </div>

    <h2>Bill to</h2>
    <div>
        <strong>{{ $order->customer_name }}</strong><br>
        <span class="muted">{{ $order->customer_email }}</span><br>
        @if($order->customer_phone)<span class="muted">{{ $order->customer_phone }}</span><br>@endif
        <span class="muted">{{ $order->delivery_address }}</span>
    </div>

    <h2>Items</h2>
    <table>
        <thead>
        <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Unit</th>
            <th>Total</th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $item)
            <tr>
                <td>
                    {{ $item['name'] ?? 'Product' }}
                    @if(!empty($item['selected_options']) && is_array($item['selected_options']))
                        <div class="muted">
                            @foreach($item['selected_options'] as $optName => $optValue)
                                {{ $optName }}: {{ $optValue }}@if(!$loop->last) · @endif
                            @endforeach
                        </div>
                    @endif
                </td>
                <td>{{ $item['quantity'] ?? 1 }}</td>
                <td>{{ strtoupper($item['currency'] ?? $order->currency) }} {{ number_format((float) ($item['unit_price'] ?? 0), 2) }}</td>
                <td>{{ strtoupper($item['currency'] ?? $order->currency) }} {{ number_format((float) ($item['total'] ?? 0), 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No line items</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="totals">
        <div><span class="muted">Subtotal</span><span>{{ strtoupper($order->currency) }} {{ number_format((float) $order->subtotal, 2) }}</span></div>
        @if((float) ($order->discount_amount ?? 0) > 0)
            <div><span class="muted">Discount</span><span>-{{ strtoupper($order->currency) }} {{ number_format((float) $order->discount_amount, 2) }}</span></div>
        @endif
        @if((float) ($order->delivery_fee ?? 0) > 0)
            <div><span class="muted">Delivery</span><span>{{ strtoupper($order->currency) }} {{ number_format((float) $order->delivery_fee, 2) }}</span></div>
        @endif
        <div class="grand"><span>Total</span><span>{{ strtoupper($order->currency) }} {{ number_format((float) $order->total_amount, 2) }}</span></div>
        <div><span class="muted">Payment</span><span style="text-transform:capitalize">{{ str_replace('_', ' ', $order->payment_status) }}</span></div>
        <div><span class="muted">Status</span><span style="text-transform:capitalize">{{ $order->status }}</span></div>
    </div>
</div>
</body>
</html>
