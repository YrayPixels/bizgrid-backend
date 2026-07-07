@extends('emails.layouts.bizgrid')

@section('title', 'Reset your password')
@section('preheader', 'Use this code to reset your password.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $user->name ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        We received a request to reset the password for your {{ config('storehause.brand_name', 'Bizgrid') }} account.
        Use the code below to set a new password.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 18px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
        <tr>
            <td style="padding:18px 20px;text-align:center;">
                <div style="font-size:13px;letter-spacing:0.08em;color:#64748b;margin-bottom:8px;">RESET CODE</div>
                <div style="font-size:28px;font-weight:800;letter-spacing:0.18em;color:#0f172a;">{{ $code }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 12px 0;font-size:14px;color:#64748b;">
        If you didn’t request this, you can safely ignore this email.
    </p>
@endsection

@section('footer')
    This message was sent because a password reset was requested for <strong>{{ $user->email }}</strong>.
@endsection

