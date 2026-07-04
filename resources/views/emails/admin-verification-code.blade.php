@extends('emails.layouts.bizgrid')

@section('title', 'Your admin verification code')
@section('preheader', 'Use this code to finish signing in.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $admin->name ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">Use the verification code below to complete your {{ config('storehause.brand_name', 'Bizgrid') }} admin sign-in.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 20px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td align="center" style="padding:20px 18px;">
                <div style="font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:10px;">Verification code</div>
                <div style="font-size:32px;font-weight:700;letter-spacing:0.25em;color:#0f172a;">{{ $code }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:14px;color:#64748b;">This code expires after use. If you did not try to sign in, you can ignore this email.</p>
@endsection

@section('footer')
    This message was sent because a sign-in was attempted for your {{ config('storehause.brand_name', 'Bizgrid') }} admin account.
@endsection
