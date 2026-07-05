@extends('emails.layouts.bizgrid')

@section('title', 'New order '.$order->order_number)
@section('preheader', 'A customer placed order '.$order->order_number)

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">You have a new order at <strong>{{ $store->name }}</strong>.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:15px;color:#0f172a;"><strong>Order:</strong> {{ $order->order_number }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Customer:</strong> {{ $order->customer_name }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Email:</strong> {{ $order->customer_email }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Phone:</strong> {{ $order->customer_phone }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Payment:</strong> {{ $awaitingPayment ? 'Awaiting payment' : ucfirst(str_replace('_', ' ', $order->payment_status)) }}</div>
            </td>
        </tr>
    </table>

    @include('emails.partials.order-summary', [
        'items' => $order->items,
        'currency' => $order->currency,
        'totalAmount' => $order->total_amount,
    ])

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $notifications->merchantDashboardUrl() }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    Open orders dashboard
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    This alert was sent because new order notifications are enabled for {{ $store->name }} on {{ config('storehause.brand_name', 'Bizgrid') }}.
@endsection
