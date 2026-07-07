@php
    $items = is_array($items ?? null) ? $items : [];
    $currency = strtoupper((string) ($currency ?? 'NGN'));
    $totalAmount = (float) ($totalAmount ?? 0);
@endphp
@if (count($items) > 0)
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;border-collapse:collapse;">
        <tr>
            <td style="padding:0 0 12px 0;font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;">
                Items in your cart
            </td>
        </tr>
        @foreach ($items as $item)
            @if (is_array($item))
                @php
                    $imageUrl = filled($item['image_url'] ?? null) ? (string) $item['image_url'] : null;
                    $quantity = (int) ($item['quantity'] ?? 1);
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    $lineTotal = (float) ($item['total'] ?? ($unitPrice * $quantity));
                @endphp
                <tr>
                    <td style="padding:12px 0;border-top:1px solid #e2e8f0;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td width="72" style="width:72px;vertical-align:top;padding-right:12px;">
                                    @if ($imageUrl)
                                        <img
                                            src="{{ $imageUrl }}"
                                            alt="{{ $item['name'] ?? 'Product' }}"
                                            width="72"
                                            height="72"
                                            style="display:block;width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;"
                                        >
                                    @else
                                        <div style="width:72px;height:72px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;"></div>
                                    @endif
                                </td>
                                <td style="vertical-align:top;">
                                    <div style="font-size:15px;font-weight:700;color:#0f172a;line-height:1.4;">{{ $item['name'] ?? 'Item' }}</div>
                                    <div style="font-size:13px;color:#64748b;margin-top:4px;">
                                        Qty {{ $quantity }} &middot; {{ $currency }} {{ number_format($unitPrice, 0) }} each
                                    </div>
                                    <div style="font-size:14px;font-weight:700;color:#0f172a;margin-top:6px;">
                                        {{ $currency }} {{ number_format($lineTotal, 0) }}
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @endif
        @endforeach
        <tr>
            <td style="padding:14px 0 0 0;border-top:1px solid #e2e8f0;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td style="font-size:14px;font-weight:700;color:#0f172a;">Cart total</td>
                        <td align="right" style="font-size:16px;font-weight:700;color:#0f172a;">
                            {{ $currency }} {{ number_format($totalAmount, 0) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endif
