@extends('emails.layouts.bizgrid')

@section('title', 'Order confirmation '.$order->order_number)
@section('preheader', 'Thanks for your order at '.$brand['name'])

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">Hi {{ $customerName ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Thanks for shopping with <strong>{{ $brand['name'] }}</strong>. We received your order
        <strong>{{ $order->order_number }}</strong>.
    </p>

    @if ($awaitingPayment)
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px 0;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;">
            <tr>
                <td style="padding:14px 16px;font-size:14px;color:#9a3412;">
                    Payment is still pending. Complete checkout to confirm your order.
                </td>
            </tr>
        </table>
    @endif

    @include('emails.partials.order-summary', [
        'items' => $order->items,
        'currency' => $order->currency,
        'totalAmount' => $order->total_amount,
    ])

    @if (filled($orderNote))
        <p style="margin:16px 0 0 0;font-size:14px;color:#64748b;">{!! nl2br(e($orderNote)) !!}</p>
    @endif

    @php($actionUrl = $awaitingPayment ? $notifications->storefrontOrderUrl($store, $order, 'checkout') : $notifications->storefrontOrderUrl($store, $order))
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    {{ $awaitingPayment ? 'Complete payment' : 'View order' }}
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    You are receiving this email because you placed an order at {{ $brand['name'] }}.
@endsection
