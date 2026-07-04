@extends('emails.layouts.bizgrid')

@section('title', 'Your admin password was reset')
@section('preheader', 'A new temporary password has been issued.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $admin->name ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Your {{ config('storehause.brand_name', 'Bizgrid') }} admin password was reset by a platform administrator.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 20px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">New login details</div>
                <div style="font-size:15px;color:#0f172a;"><strong>Email:</strong> {{ $admin->email }}</div>
                <div style="font-size:15px;color:#0f172a;margin-top:6px;"><strong>Password:</strong> {{ $password }}</div>
            </td>
        </tr>
    </table>

    @php($adminUrl = rtrim((string) ($brand['admin_app_url'] ?? config('storehause.admin_app_url')), '/'))
    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                <a href="{{ $adminUrl }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Sign in to admin</a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0 0;font-size:14px;color:#64748b;">If you did not expect this reset, contact platform support immediately.</p>
@endsection

@section('footer')
    This message was sent because your {{ config('storehause.brand_name', 'Bizgrid') }} admin password was reset.
@endsection
