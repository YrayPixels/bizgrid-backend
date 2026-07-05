@extends('emails.layouts.bizgrid')

@section('title', 'Payment received '.$order->order_number)
@section('preheader', 'Your payment for order '.$order->order_number.' was successful.')

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">Hi {{ $customerName ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        We received your payment for order <strong>{{ $order->order_number }}</strong> at
        <strong>{{ $brand['name'] }}</strong>.
    </p>

    @include('emails.partials.order-summary', [
        'items' => $order->items,
        'currency' => $order->currency,
        'totalAmount' => $order->total_amount,
    ])

    @if (filled($orderNote))
        <p style="margin:16px 0 0 0;font-size:14px;color:#64748b;">{!! nl2br(e($orderNote)) !!}</p>
    @endif

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $notifications->storefrontOrderUrl($store, $order) }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    View order confirmation
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    You are receiving this email because you paid for an order at {{ $brand['name'] }}.
@endsection
