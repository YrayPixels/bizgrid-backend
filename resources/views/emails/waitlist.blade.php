<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to HeySolana</title>
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

        .logo {
            max-width: 150px;
        }

        h1 {
            color: #333;
        }

        p {
            color: #555;
            font-size: 16px;
            line-height: 1.5;
        }

        .btn {
            display: inline-block;
            text-align: center;
            padding: 10px;
            margin: 20px 0;
            background: #971BB2;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            border-radius: 5px;
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
            <h4 style="">Hello {{ $data['first_name'] ?? 'there!' }},</h4>
            <h4>Welcome to HeySolana 🚀</h4>
        </div>

        <div style="text-align: justify; padding:0px 20px 0px 20px;">

            <p>You're officially on the HeySolana waitlist—welcome to the future of hands-free blockchain transactions!
                🎉</p>
            <p>
                ✅ <strong>Voice-Powered Transactions</strong> – Swap, send, and automate with simple voice commands.<br>
                ✅ <strong>AI-Driven Portfolio Insights</strong> – Smart recommendations tailored to you.<br>
                ✅ <strong>Seamless DeFi Access</strong> – Staking, lending, and yield farming at your command.
            </p>
            <p>We’ll be rolling our application soon, and you’ll be among the first to experience the future of
                Solana-powered AI transactions. Stay tuned for updates!</p>
            <div style="text-align:center; margin: 5px 0px 5px 0px;">
            </div>
            <p class="">Thanks for joining us! <br> <strong>The HeySolana Team</strong></p>

        </div>

        <div
            style=" background:#171717;  color:white; padding:20px; 
            border-radius: 0px 0px 10px 10px;

        ">
            <div style=" justify-content:space-between;">
                <p class="btn">Join the Community</p>
                <div>
                    <p class="footer">Follow us for the latest updates:</p>
                    <div class="social-links">
                        🔗 <a href="https://x.com/useHeysolana">Twitter</a> |
                        🔗 <a href="https://t.me/+mT-FJs0poLc5OTBl">Telegram</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

</body>

</html>
