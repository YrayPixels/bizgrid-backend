@extends('emails.layouts.bizgrid')

@section('title', 'Low stock alert')
@section('preheader', $product->name.' is running low at '.$store->name)

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">
        <strong>{{ $product->name }}</strong> at <strong>{{ $store->name }}</strong> is running low.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px 0;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:15px;color:#9a3412;"><strong>Remaining stock:</strong> {{ $product->stock_quantity }}</div>
                @if (filled($product->sku))
                    <div style="font-size:15px;color:#9a3412;margin-top:6px;"><strong>SKU:</strong> {{ $product->sku }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ rtrim((string) config('storehause.app_url'), '/').'/admin/products' }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    Manage products
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    This alert was sent because low-stock notifications are enabled for {{ $store->name }} on {{ config('storehause.brand_name', 'Bizgrid') }}.
@endsection
