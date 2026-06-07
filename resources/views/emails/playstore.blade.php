<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to AgentX</title>
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

         .cta-button {
      display: inline-block;
      padding: 12px 24px;
      margin-top: 20px;
      background-color: #2eeb44;
      color: white;
      text-decoration: none;
      font-weight: bold;
      border-radius: 8px;
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
                                 <img src="https://heysolana.yraylabs.fun/pngs/logo.png" alt="HeySolana Logo"
                                    width="40">
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
            <h4 style="">Hi {{ $data['first_name'] ?? 'there!' }},</h4>
        </div>

        <div style="text-align: justify; padding:0px 20px 0px 20px;">

    <p>
      We're thrilled to invite you to be one of the first beta testers for <strong>HeySolana</strong> — your on-chain voice assistant that helps interact with the Solana blockchain hands-free—whether it's sending transactions, swapping tokens, or analyzing
                crypto projects,.
    </p>
    <p>
      As a beta tester, you'll have the opportunity to explore our app before its official launch and provide valuable feedback to help us improve.
    </p>
    <p>
      We’d love your feedback as we gear up for launch. Click below to start testing:
    </p>
    <a class="btn"  href="https://play.google.com/store/apps/details?id=com.yraylabs.heysolana" target="_blank">Test HeySolana Now</a>
    <p class="">
      Thank you for being a part of our early journey! 💚<br/>
      — The HeySolana Team
    </p>
           
        </div>

        <div
            style=" background:#171717;  color:white; padding:20px; 
            border-radius: 0px 0px 10px 10px;

        ">
            <div style=" justify-content:space-between;">
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
