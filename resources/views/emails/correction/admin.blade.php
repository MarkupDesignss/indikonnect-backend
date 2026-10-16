<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Correction Request</title>
</head>

<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
    <div
        style="max-width:600px;margin:30px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">

        <div style="background:#1e3a8a;color:#fff;padding:18px 24px;">
            <h2 style="margin:0;font-size:18px;">📩 New Profile Correction Request</h2>
        </div>

        <div style="padding:24px;color:#333;font-size:14px;line-height:1.6;">
            <p>Hi Admin,</p>
            <p>A distributor has requested to update their <strong>Full Name</strong> and <strong>Date of
                    Birth</strong>.</p>

            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #eee;width:40%;"><strong>Distributor ID</strong></td>
                    <td style="padding:8px;border-bottom:1px solid #eee;">{{ $req->user->distributor_id ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Current Name</strong></td>
                    <td style="padding:8px;border-bottom:1px solid #eee;">{{ $req->user->full_name }}</td>
                </tr>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Current DOB</strong></td>
                    <td style="padding:8px;border-bottom:1px solid #eee;">
                        {{ optional($req->user->date_of_birth)->format('d M Y') ?? 'N/A' }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Requested Name</strong></td>
                    <td style="padding:8px;border-bottom:1px solid #eee;color:#b91c1c;">
                        <strong>{{ $req->full_name }}</strong></td>
                </tr>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Requested DOB</strong></td>
                    <td style="padding:8px;border-bottom:1px solid #eee;color:#b91c1c;">
                        <strong>{{ $req->date_of_birth->format('d M Y') }}</strong></td>
                </tr>
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #eee;"><strong>User Email</strong></td>
                    <td style="padding:8px;border-bottom:1px solid #eee;">{{ $req->user->email }}</td>
                </tr>
                <tr>
                    <td style="padding:8px;"><strong>Request ID</strong></td>
                    <td style="padding:8px;">#{{ $req->id }}</td>
                </tr>
            </table>

            <p>Please log in to the admin panel to review and send a correction link.</p>

            <p style="margin:24px 0;">
                <a href="{{ url('/admin/correction') }}"
                    style="background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold;">
                    Open Admin Panel
                </a>
            </p>
        </div>

        <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#888;text-align:center;">
            This is an automated message. Please do not reply.
        </div>
    </div>
</body>

</html>
