<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Ready - Tukaatu Express</title>
</head>
<body style="margin: 0; padding: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #111827;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 620px; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding: 24px 28px; background: #0f172a; color: #ffffff;">
                            <div style="font-size: 13px; opacity: 0.8; margin-bottom: 6px;">Tukaatu Express</div>
                            <h1 style="margin: 0; font-size: 23px;">Backup Ready</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px 28px;">
                            <p style="line-height: 1.6;">Hello,</p>
                            <p style="line-height: 1.6;">Your backup request has been completed successfully.</p>
                            <table width="100%" cellpadding="10" cellspacing="0" role="presentation" style="margin: 22px 0; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;">
                                <tr>
                                    <td><strong>Backup File</strong></td>
                                    <td>{{ $backupFile }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Size</strong></td>
                                    <td>{{ $backupSize }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Created</strong></td>
                                    <td>{{ date('Y-m-d H:i:s') }}</td>
                                </tr>
                            </table>
                            <p style="line-height: 1.6;">The backup file has been attached to this email for your records.</p>
                            <p style="font-size: 13px; color: #6b7280;">
                                <strong>⚠️ Important:</strong> This backup is also available for download in your admin panel.
                            </p>
                            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 26px 0;">
                            <p style="margin-bottom: 0;"> Regards,<br>Tukaatu Express</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>