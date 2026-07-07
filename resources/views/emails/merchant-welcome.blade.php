@extends('emails.layouts.bizgrid')

@section('title', 'Welcome to '.config('storehause.brand_name', 'Bizgrid'))
@section('preheader', 'Your account is ready — let\'s set up your store.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $user->name ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Welcome to {{ config('storehause.brand_name', 'Bizgrid') }}. We're glad you joined.
        Your merchant account is ready, and you can start building your online store in minutes.
    </p>

    <p style="margin:0 0 16px 0;">Here's what to do next:</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 20px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:15px;color:#0f172a;margin-bottom:8px;"><strong>1.</strong> Complete store onboarding</div>
                <div style="font-size:15px;color:#0f172a;margin-bottom:8px;"><strong>2.</strong> Add your products and branding</div>
                <div style="font-size:15px;color:#0f172a;"><strong>3.</strong> Publish your storefront and start selling</div>
            </td>
        </tr>
    </table>

    @php($onboardingUrl = rtrim((string) ($brand['app_url'] ?? config('storehause.app_url')), '/').'/admin/onboarding')
    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $onboardingUrl }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Set up your store</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0 0;font-size:14px;color:#64748b;">
        Signed up as <strong>{{ $user->email }}</strong>. If you have questions, reply to this email — we're happy to help.
    </p>
@endsection

@section('footer')
    This message was sent because you created a {{ config('storehause.brand_name', 'Bizgrid') }} merchant account.
@endsection
