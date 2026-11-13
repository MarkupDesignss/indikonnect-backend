<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Confirm Your New Email</title>
</head>

<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,sans-serif">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:40px 0">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0"
                    style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05)">
                    <tr>
                        <td style="background:#1e3a8a;color:#fff;padding:18px 24px;text-align:center;">
                            <img src="https://www.markupdesigns.net/indikonnect/storage/logos/MmVFOudCiVU3Y8C0sf2gEEXna5jSKroCDj8xYMo0.png"
                                alt="Logo" style="max-height:44px;display:block;margin:0 auto 10px;">
                            <h2 style="margin:0;font-size:18px;">📧 Confirm Your New Email</h2>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 40px;color:#374151;font-size:15px;line-height:1.6">
                            <p style="margin-top:0">Hello <strong>{{ $userName }}</strong>,</p>

                            <p>Your email change request has been approved.
                                Click the button below to confirm your new email address.</p>

                            <div
                                style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:16px 0;">
                                <p style="margin:0;"><strong>New Email:</strong> {{ $newEmail }}</p>
                            </div>

                            <table cellpadding="0" cellspacing="0" style="margin:24px 0">
                                <tr>
                                    <td align="center" style="border-radius:6px;background:#2563eb">
                                        <a href="{{ $link }}"
                                            style="display:inline-block;padding:12px 32px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px">
                                            Confirm New Email
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size:13px;color:#6b7280">
                                This link will expire in <strong>{{ $expiresInMinutes }} minutes</strong>
                                and can only be used once.
                            </p>

                            <p style="font-size:13px;color:#6b7280;margin-bottom:0">
                                If the button does not work, copy and paste the following link into your browser:<br>
                                <a href="{{ $link }}"
                                    style="color:#2563eb;word-break:break-all">{{ $link }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#888;text-align:center;">
                            Automated message. Do not reply.<br>
                            <a href="{{ config('app.frontend_url', env('FRONTEND_URL')) }}"
                                style="color:#2563eb;text-decoration:none;">{{ config('app.frontend_url', env('FRONTEND_URL')) }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
