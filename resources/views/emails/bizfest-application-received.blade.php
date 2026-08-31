@extends('emails.layouts.bizgrid')

@section('title', 'BizFest application received')
@section('preheader', 'We got your BizFest application — here\'s what happens next.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $application->owner_name }},</p>

    <p style="margin:0 0 16px 0;">
        Welcome to <strong>BizFest 1.0</strong>. We've received your application for
        <strong>{{ $application->business_name }}</strong> and our team will review it shortly.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 20px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:13px;color:#64748b;margin-bottom:4px;">Application summary</div>
                <div style="font-size:15px;color:#0f172a;margin-bottom:6px;"><strong>Business:</strong> {{ $application->business_name }}</div>
                <div style="font-size:15px;color:#0f172a;margin-bottom:6px;"><strong>Category:</strong> {{ $application->category }}</div>
                <div style="font-size:15px;color:#0f172a;"><strong>City:</strong> {{ $application->city }}</div>
            </td>
        </tr>
    </table>

    @if ($application->has_store)
        <p style="margin:0 0 16px 0;">
            We can already see a Bizgrid store linked to this email — great start. Keep products and branding updated so you're ready when the programme kicks off.
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
            <tr>
                <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                    <a href="{{ rtrim($brand['app_url'], '/') }}/admin" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Open your store</a>
                </td>
            </tr>
        </table>
    @else
        <p style="margin:0 0 16px 0;">
            BizFest is for sellers building a proper online store. Create your Bizgrid store next — it's free to start and helps you compete for the prize pool.
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
            <tr>
                <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                    <a href="{{ $signupUrl }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Create your store</a>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin:20px 0 0 0;font-size:14px;color:#64748b;">
        Applied as <strong>{{ $application->email }}</strong>.
        You can revisit programme details anytime at
        <a href="{{ $grantsUrl }}" style="color:{{ $brand['primary_color'] ?? '#0d9488' }};text-decoration:none;">{{ $grantsUrl }}</a>.
    </p>

    @if (!empty($socialLinks))
        <p style="margin:24px 0 12px 0;font-size:15px;color:#0f172a;">
            <strong>Stay close to BizFest</strong> — follow Bizgrid for updates, tips, and announcements:
        </p>
        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
            <tr>
                @foreach ($socialLinks as $link)
                    <td style="padding-right:10px;padding-bottom:8px;">
                        <a href="{{ $link['href'] }}" style="display:inline-block;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;font-weight:600;color:#0f172a;text-decoration:none;">
                            {{ $link['label'] }}
                        </a>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
@endsection

@section('footer')
    This message was sent because you applied to BizFest on {{ config('storehause.brand_name', 'Bizgrid') }}.
@endsection
