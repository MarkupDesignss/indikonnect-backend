<!DOCTYPE html>
<html>

<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
    <div
        style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <div style="background:#7c3aed;color:#fff;padding:18px 24px;text-align:center;">
            <img src="https://www.markupdesigns.net/indikonnect/storage/logos/MmVFOudCiVU3Y8C0sf2gEEXna5jSKroCDj8xYMo0.png"
                alt="Logo" style="max-height:44px;display:block;margin:0 auto 10px;">
            <h2 style="margin:0;font-size:18px;">🔗 Action Required: Update Your Details</h2>
        </div>

        <div style="padding:24px;color:#333;font-size:14px;line-height:1.6;">
            <p>Hi <strong>{{ $req->user->full_name }}</strong>,</p>

            <p>Your correction request <strong>#{{ $req->id }}</strong> has been
                <strong style="color:#16a34a;">approved</strong>.
            </p>

            <p><strong>Reason you selected:</strong> {{ $req->reason_label }}</p>

            <p>Click the button below to update your
                <strong>
                    {{ collect($req->editable_fields)->map(fn($f) => $f === 'full_name' ? 'Full Name' : 'Date of Birth')->join(' & ') }}
                </strong>:
            </p>

            <p style="text-align:center;margin:28px 0;">
                <a href="{{ $url }}"
                    style="background:#16a34a;color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">
                    Update My Details
                </a>
            </p>

            <p style="font-size:13px;color:#666;word-break:break-all;">
                Or copy this link:<br>
                <a href="{{ $url }}" style="color:#2563eb;">{{ $url }}</a>
            </p>

            <div
                style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:20px 0;">
                <p style="margin:0;"><strong>⏰ Link expires:</strong>
                    {{ $req->token_expires_at->format('d M Y, h:i A') }}</p>
                <p style="margin:6px 0 0;"><strong>🔒 Single use only.</strong></p>
            </div>

            <p>Regards,<br>Support Team</p>
        </div>

        <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#888;text-align:center;">
            Automated message. Do not reply.<br>
            <a href="{{ config('app.frontend_url', env('FRONTEND_URL')) }}"
                style="color:#2563eb;text-decoration:none;">{{ config('app.frontend_url', env('FRONTEND_URL')) }}</a>
        </div>
    </div>
</body>

</html>
