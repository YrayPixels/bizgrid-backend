@extends('emails.layouts.bizgrid')

@section('title', 'Billing update')
@section('preheader', 'An update about your '.config('storehause.brand_name', 'Bizgrid').' subscription')

@section('content')
    @php($notifications = app(\App\Services\StoreNotificationService::class))
    <p style="margin:0 0 16px 0;">Hi {{ $merchant->contact_name ?? $merchant->business_name }},</p>

    @if ($event === 'subscription_active')
        <p style="margin:0 0 16px 0;">
            Your {{ config('storehause.brand_name', 'Bizgrid') }} subscription is now active on the
            <strong>{{ ucfirst((string) ($context['plan'] ?? $merchant->subscription_plan)) }}</strong> plan.
        </p>
        @if (filled($context['renews_at'] ?? null))
            <p style="margin:0 0 16px 0;">Your next renewal date is <strong>{{ $context['renews_at'] }}</strong>.</p>
        @endif
    @elseif ($event === 'subscription_on_hold')
        <p style="margin:0 0 16px 0;">
            Your {{ config('storehause.brand_name', 'Bizgrid') }} subscription is on hold. Update your billing details to restore full access.
        </p>
    @elseif ($event === 'subscription_cancelled')
        <p style="margin:0 0 16px 0;">
            Your {{ config('storehause.brand_name', 'Bizgrid') }} subscription has been cancelled or expired.
        </p>
    @elseif ($event === 'add_on_purchased')
        <p style="margin:0 0 16px 0;">
            Your add-on purchase was successful
            @if (filled($context['add_on_label'] ?? null))
                : <strong>{{ $context['add_on_label'] }}</strong>
            @endif.
        </p>
    @else
        <p style="margin:0 0 16px 0;">There is an update to your {{ config('storehause.brand_name', 'Bizgrid') }} billing account.</p>
    @endif

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 0 0;">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $notifications->billingSettingsUrl() }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    Manage billing
                </a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    This message was sent about billing for {{ $merchant->business_name }} on {{ config('storehause.brand_name', 'Bizgrid') }}.
@endsection
