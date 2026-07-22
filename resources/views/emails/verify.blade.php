<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; padding:0; background:#f4f4f5; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; background:#ffffff; border-radius: 8px; padding: 40px;">
                    <tr>
                        <td style="font-size: 20px; font-weight: 700; color: #18181b; padding-bottom: 16px;">
                            {{ __('Confirm your subscription') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size: 15px; line-height: 1.6; color: #3f3f46; padding-bottom: 24px;">
                            {{ __('Click the button below to confirm you want to receive emails from us. If you didn\'t sign up, you can safely ignore this email.') }}
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <a href="{{ $verifyUrl }}" style="display: inline-block; background: #18181b; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; padding: 12px 28px; border-radius: 6px;">
                                {{ __('Confirm subscription') }}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size: 13px; line-height: 1.5; color: #71717a;">
                            {{ __('Or paste this link into your browser:') }}<br>
                            <a href="{{ $verifyUrl }}" style="color: #52525b; word-break: break-all;">{{ $verifyUrl }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
