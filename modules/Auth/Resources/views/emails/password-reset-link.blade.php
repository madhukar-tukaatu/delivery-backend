<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="margin: 0; padding: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #111827;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 620px; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding: 24px 28px; background: #0f172a; color: #ffffff;">
                            <div style="font-size: 13px; opacity: 0.8; margin-bottom: 6px;">Tukaatu Express</div>
                            <h1 style="margin: 0; font-size: 23px;">Reset Your Password</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px 28px;">
                            <p style="line-height: 1.6;">Hello {{ $user->name }},</p>
                            <p style="line-height: 1.6;">You are receiving this email because we received a password reset request for your account.</p>
                            <table width="100%" cellpadding="10" cellspacing="0" role="presentation" style="margin: 22px 0; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;">
                                <tr>
                                    <td><strong>Email</strong></td>
                                    <td>{{ $user->email }}</td>
                                </tr>
                            </table>
                            <p style="line-height: 1.6;">Click the button below to reset your password.</p>
                            <p style="margin: 28px 0; text-align: center;">
                                <a href="{{ $resetUrl }}" style="display: inline-block; padding: 14px 26px; background: #1677ff; color: #ffffff; text-decoration: none; border-radius: 7px; font-weight: 700;">Reset Password</a>
                            </p>
                            <p style="font-size: 13px; color: #6b7280; line-height: 1.6;">This password reset link will expire in 60 minutes.</p>
                            <p style="font-size: 13px; color: #6b7280;">If you did not request a password reset, no further action is required.</p>
                            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 26px 0;">
                            <p style="margin-bottom: 0;">Regards,<br>Tukaatu Express</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
