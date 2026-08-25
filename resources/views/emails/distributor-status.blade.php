<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distributor Account Status</title>
</head>

<body style="margin:0; padding:0; background-color:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color:#f4f6f8; padding:40px 15px;">

        <tr>
            <td align="center">

                <table width="600" cellpadding="0" cellspacing="0" border="0"
                    style="max-width:600px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden;">

                    {{-- Header --}}
                    <tr>
                        <td style="background:#111827; padding:25px 30px; text-align:center;">
                            <h1 style="margin:0; color:#ffffff; font-size:24px; font-weight:600;">
                                Distributor Account
                            </h1>

                            <p style="margin:8px 0 0; color:#d1d5db; font-size:14px;">
                                Account Status Update
                            </p>
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td style="padding:40px 35px;">

                            <h2 style="margin:0 0 15px; color:#111827; font-size:22px;">
                                Hello {{ $distributor->full_name }},
                            </h2>

                            @if ($status === 'active')
                                <div
                                    style="background:#ecfdf5; border-left:4px solid #10b981; padding:18px 20px; border-radius:6px; margin:25px 0;">
                                    <p style="margin:0; color:#047857; font-size:18px; font-weight:600;">
                                        ✓ Your account is now active
                                    </p>
                                </div>

                                <p style="margin:0 0 15px; color:#4b5563; font-size:15px; line-height:1.7;">
                                    We're pleased to inform you that your distributor account
                                    has been <strong style="color:#111827;">activated</strong>
                                    by the administrator.
                                </p>

                                <p style="margin:0; color:#4b5563; font-size:15px; line-height:1.7;">
                                    You can now access your distributor account and continue
                                    using the available services.
                                </p>
                            @else
                                <div
                                    style="background:#fef2f2; border-left:4px solid #ef4444; padding:18px 20px; border-radius:6px; margin:25px 0;">
                                    <p style="margin:0; color:#b91c1c; font-size:18px; font-weight:600;">
                                        ⚠ Your account has been suspended
                                    </p>
                                </div>

                                <p style="margin:0 0 15px; color:#4b5563; font-size:15px; line-height:1.7;">
                                    Your distributor account has been
                                    <strong style="color:#111827;">suspended</strong>
                                    by the administrator.
                                </p>

                                <p style="margin:0; color:#4b5563; font-size:15px; line-height:1.7;">
                                    If you believe this action was taken by mistake or
                                    you need further information, please contact the
                                    administrator.
                                </p>
                            @endif

                            {{-- Account Details --}}
                            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                style="margin-top:30px; background:#f9fafb; border-radius:8px;">

                                <tr>
                                    <td style="padding:15px 18px; color:#6b7280; font-size:14px;">
                                        Account Name
                                    </td>

                                    <td align="right"
                                        style="padding:15px 18px; color:#111827; font-size:14px; font-weight:600;">
                                        {{ $distributor->full_name }}
                                    </td>
                                </tr>

                                <tr>
                                    <td
                                        style="padding:15px 18px; color:#6b7280; font-size:14px; border-top:1px solid #e5e7eb;">
                                        Account Status
                                    </td>

                                    <td align="right"
                                        style="padding:15px 18px; font-size:14px; font-weight:600; border-top:1px solid #e5e7eb;
                                        color: {{ $status === 'active' ? '#059669' : '#dc2626' }};">
                                        {{ ucfirst($status) }}
                                    </td>
                                </tr>

                            </table>

                            <p style="margin:30px 0 0; color:#4b5563; font-size:14px; line-height:1.6;">
                                Thank you for being a part of our platform.
                            </p>

                            <p style="margin:20px 0 0; color:#111827; font-size:14px; line-height:1.6;">
                                Regards,<br>
                                <strong>Admin Team</strong>
                            </p>

                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td
                            style="background:#f9fafb; padding:20px 30px; text-align:center; border-top:1px solid #e5e7eb;">
                            <p style="margin:0; color:#9ca3af; font-size:12px;">
                                This is an automated email. Please do not reply directly to this email.
                            </p>

                            <p style="margin:8px 0 0; color:#9ca3af; font-size:12px;">
                                © {{ date('Y') }} Admin Team. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>

    </table>

</body>

</html>
