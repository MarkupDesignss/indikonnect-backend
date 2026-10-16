<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Request Received</title>
</head>

<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
    <div
        style="max-width:600px;margin:30px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">

        <div style="background:#065f46;color:#fff;padding:18px 24px;">
            <h2 style="margin:0;font-size:18px;">✅ Request Received</h2>
        </div>

        <div style="padding:24px;color:#333;font-size:14px;line-height:1.6;">
            <p>Hi <strong>{{ $req->user->full_name }}</strong>,</p>

            <p>We've received your request to update your <strong>Full Name</strong> and <strong>Date of Birth</strong>.
            </p>

            <div
                style="background:#ecfdf5;border-left:4px solid #10b981;padding:12px 16px;border-radius:4px;margin:16px 0;">
                <p style="margin:0;"><strong>Request ID:</strong> #{{ $req->id }}</p>
                <p style="margin:6px 0 0;"><strong>Status:</strong> Pending admin review</p>
            </div>

            <p>Your request has reached our admin team. You will receive a follow-up email with a secure link shortly.
                Use that link to update your details.</p>

            <p style="color:#888;font-size:13px;">
                If you did not make this request, please contact support immediately.
            </p>

            <p>Regards,<br>Support Team</p>
        </div>

        <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#888;text-align:center;">
            This is an automated message. Please do not reply.
        </div>
    </div>
</body>

</html>
