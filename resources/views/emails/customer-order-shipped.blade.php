@extends('emails.layouts.bizgrid')

@section('title', 'Order shipped '.$order->order_number)
@section('preheader', 'Your order '.$order->order_number.' is on the way.')

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">Hi {{ $customerName ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Good news — your order <strong>{{ $order->order_number }}</strong> from
        <strong>{{ $brand['name'] }}</strong> has shipped.
    </p>

    @if (filled($trackingNumber))
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px 0;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">
            <tr>
                <td style="padding:14px 16px;font-size:14px;color:#166534;">
                    <strong>Tracking number:</strong> {{ $trackingNumber }}
                </td>
            </tr>
        </table>
    @endif

    @include('emails.partials.order-summary', [
        'items' => $order->items,
        'currency' => $order->currency,
        'totalAmount' => $order->total_amount,
    ])

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $notifications->storefrontOrderUrl($store, $order) }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    View order
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    You are receiving this email because you placed an order at {{ $brand['name'] }}.
@endsection
