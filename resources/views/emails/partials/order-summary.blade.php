@php
    $items = is_array($items ?? null) ? $items : [];
    $currency = strtoupper((string) ($currency ?? 'NGN'));
    $totalAmount = (float) ($totalAmount ?? 0);
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:16px 0 0 0;border-collapse:collapse;">
    <tr>
        <td style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;">Item</td>
        <td align="center" style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;">Qty</td>
        <td align="right" style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;">Total</td>
    </tr>
    @foreach ($items as $item)
        @if (is_array($item))
            <tr>
                <td style="padding:10px 12px;border:1px solid #e2e8f0;font-size:14px;color:#0f172a;">{{ $item['name'] ?? 'Item' }}</td>
                <td align="center" style="padding:10px 12px;border:1px solid #e2e8f0;font-size:14px;color:#334155;">{{ $item['quantity'] ?? 1 }}</td>
                <td align="right" style="padding:10px 12px;border:1px solid #e2e8f0;font-size:14px;color:#334155;">
                    {{ $currency }} {{ number_format((float) ($item['total'] ?? 0), 0) }}
                </td>
            </tr>
        @endif
    @endforeach
    <tr>
        <td colspan="2" align="right" style="padding:12px;border:1px solid #e2e8f0;font-size:14px;font-weight:700;color:#0f172a;">Order total</td>
        <td align="right" style="padding:12px;border:1px solid #e2e8f0;font-size:14px;font-weight:700;color:#0f172a;">
            {{ $currency }} {{ number_format($totalAmount, 0) }}
        </td>
    </tr>
</table>
