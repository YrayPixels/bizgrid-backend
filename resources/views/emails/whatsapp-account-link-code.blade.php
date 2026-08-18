@extends('emails.layouts.bizgrid')

@section('title', 'Confirm WhatsApp access')
@section('preheader', 'Use this code in WhatsApp to link your store.')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $user->name ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Someone is trying to manage your {{ config('storehause.brand_name', 'Bizgrid') }} store from WhatsApp{{ $whatsappHint ? ' ('.$whatsappHint.')' : '' }}.
        Reply with this code in that chat only if it was you.
    </p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 18px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
        <tr>
            <td style="padding:18px 20px;text-align:center;">
                <div style="font-size:13px;letter-spacing:0.08em;color:#64748b;margin-bottom:8px;">WHATSAPP LINK CODE</div>
                <div style="font-size:28px;font-weight:800;letter-spacing:0.18em;color:#0f172a;">{{ $code }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 12px 0;font-size:14px;color:#64748b;">
        This code expires in 10 minutes. If you didn’t request this, ignore the email — your store stays unchanged.
    </p>
@endsection

@section('footer')
    This message was sent to <strong>{{ $user->email }}</strong> to confirm WhatsApp store management.
@endsection
