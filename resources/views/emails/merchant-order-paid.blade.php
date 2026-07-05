@extends('emails.layouts.bizgrid')

@section('title', 'Payment received '.$order->order_number)
@section('preheader', 'Payment confirmed for order '.$order->order_number)

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">Payment was received for order <strong>{{ $order->order_number }}</strong> at <strong>{{ $store->name }}</strong>.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:15px;color:#0f172a;"><strong>Customer:</strong> {{ $order->customer_name }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Total:</strong> {{ strtoupper($order->currency) }} {{ number_format((float) $order->total_amount, 0) }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Settlement:</strong> {{ ucfirst(str_replace('_', ' ', (string) ($order->settlement_status ?? 'pending'))) }}</div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $notifications->merchantDashboardUrl() }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    View order in dashboard
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    This alert was sent because order notifications are enabled for {{ $store->name }} on {{ config('storehause.brand_name', 'Bizgrid') }}.
@endsection
