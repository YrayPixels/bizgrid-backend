<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HeySolana Admin Password Reset</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        p {
            color: #555;
            font-size: 16px;
            line-height: 1.5;
        }

        .footer {
            margin-top: 20px;
            font-size: 14px;
            color: #777;
            text-align: center;
        }

        .social-links a {
            margin: 0 10px;
            text-decoration: none;
            color: #971BB2;
            font-weight: bold;
        }
    </style>
</head>

<body>

    <div class="container">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
            <tr>
                <td align="center" style="padding: 20px 0px 0px 0px;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                        <tr>
                            <td style="padding: 4px;">
                                <img src="https://heysolana.yraylabs.fun/pngs/logo.png" alt="HeySolana Logo" width="40">
                            </td>
                            <td>
                                <h2 style="margin: 0; font-size: 24px; font-family: Arial, sans-serif;">HeySolana</h2>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div style="text-align: start; padding:0px 20px 0px 20px;">
            <h4>Hi {{ $admin->name ?? 'there!' }},</h4>
        </div>

        <div style="text-align: justify; padding:0px 20px 0px 20px;">
            <p>Your admin password has been reset by a dashboard administrator.</p>

            <p>
                You can now log in with:
                <br>
                Email: {{ $admin->email ?? 'not provided' }}
                <br>
                Password: {{ $password ?? 'not provided' }}
            </p>

            <p>If you did not expect this, please reply to this email immediately.</p>
        </div>

        <div style=" background:#171717;  color:white; padding:20px; border-radius: 0px 0px 10px 10px;">
            <p class="footer" style="color: #fff;">Follow us for the latest updates:</p>
            <div class="social-links">
                🔗 <a href="https://x.com/useHeysolana">Twitter</a> |
                🔗 <a href="https://t.me/+mT-FJs0poLc5OTBl">Telegram</a>
            </div>
        </div>
    </div>

</body>

</html>

