@php
    $brand = $brand ?? \App\Support\MailBranding::platform();
    $brandName = $brand['name'] ?? config('app.name', 'Bizgrid');
    $logoUrl = $brand['logo_url'] ?? null;
    $primaryColor = $brand['primary_color'] ?? '#0d9488';
    $appUrl = $brand['app_url'] ?? config('storehause.app_url');
    $supportEmail = $brand['support_email'] ?? config('mail.from.address');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', $brandName)</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f7f6;font-family:Arial,Helvetica,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f4f7f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,0.08);">
                <tr>
                    <td style="padding:24px 28px 12px 28px;border-bottom:1px solid #e2e8f0;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                @if ($logoUrl)
                                    <td style="padding-right:12px;vertical-align:middle;">
                                        <img src="{{ $logoUrl }}" alt="{{ $brandName }} logo" width="40" height="40" style="display:block;border-radius:8px;">
                                    </td>
                                @endif
                                <td style="vertical-align:middle;">
                                    <div style="font-size:20px;font-weight:700;color:#0f172a;line-height:1.2;">{{ $brandName }}</div>
                                    @hasSection('preheader')
                                        <div style="font-size:13px;color:#64748b;margin-top:4px;">@yield('preheader')</div>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;font-size:16px;line-height:1.6;color:#334155;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 28px;background:#0f172a;color:#cbd5e1;font-size:13px;line-height:1.5;">
                        <div style="margin-bottom:8px;">@yield('footer', 'You are receiving this email from '.$brandName.'.')</div>
                        @if ($supportEmail)
                            <div>Need help? Reply to this email or contact <a href="mailto:{{ $supportEmail }}" style="color:#5eead4;text-decoration:none;">{{ $supportEmail }}</a>.</div>
                        @endif
                        @if ($appUrl)
                            <div style="margin-top:12px;"><a href="{{ $appUrl }}" style="color:#5eead4;text-decoration:none;">{{ $appUrl }}</a></div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
