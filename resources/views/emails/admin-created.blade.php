@extends('emails.layouts.bizgrid')

@section('title', 'Welcome to '.config('storehause.brand_name', 'Bizgrid').' Admin')
@section('preheader', 'Your admin account is ready.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $admin->name ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Welcome to {{ config('storehause.brand_name', 'Bizgrid') }}. Your platform admin account has been created.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 20px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Login details</div>
                <div style="font-size:15px;color:#0f172a;"><strong>Email:</strong> {{ $admin->email }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Password:</strong> {{ $password }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 20px 0;">Sign in to the admin dashboard to manage merchants, stores, and platform settings.</p>

    @php($adminUrl = rtrim((string) ($brand['admin_app_url'] ?? config('storehause.admin_app_url')), '/'))
    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $adminUrl }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Open admin dashboard</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0 0;font-size:14px;color:#64748b;">For security, change your password after your first login.</p>
@endsection

@section('footer')
    This message was sent because a {{ config('storehause.brand_name', 'Bizgrid') }} admin account was created for you.
@endsection
