<!DOCTYPE html>
<html>

<head>
    <title>Confirm Email Update</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>

<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
    <div
        style="max-width:480px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <div style="background:#7c3aed;color:#fff;padding:18px 24px;text-align:center;">
            <img src="https://www.markupdesigns.net/indikonnect/storage/logos/MmVFOudCiVU3Y8C0sf2gEEXna5jSKroCDj8xYMo0.png"
                alt="Logo" style="max-height:44px;display:block;margin:0 auto 10px;">
            <h3 style="margin:0;font-size:17px;">Confirm Your New Email Address</h3>
        </div>

        <div style="padding:24px;color:#333;font-size:14px;line-height:1.6;">
            <p style="margin-top:0;">Request #{{ $req->id }} — {{ $req->email }}</p>

            <p style="color:#7c3aed;font-weight:bold;margin-top:-6px;">
                Reason: {{ $req->reason_label }}
            </p>

            @if ($errors->any())
                <p style="color:red">{{ $errors->first() }}</p>
            @endif

            <form method="POST" action="{{ route('email.correction.update', $token) }}" id="emailForm">
                @csrf

                <label style="font-weight:bold;">New Email Address</label><br>
                <input type="email" name="new_email" id="new_email" value="{{ old('new_email', $req->new_email) }}"
                    required
                    style="width:100%;padding:10px;margin:6px 0 16px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;"><br>

                <button type="submit"
                    style="background:#16a34a;color:#fff;padding:12px 24px;border:none;border-radius:6px;font-weight:bold;cursor:pointer;width:100%;">
                    Confirm
                </button>
            </form>
        </div>

        <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#888;text-align:center;">
            <a href="{{ config('app.frontend_url', env('FRONTEND_URL')) }}"
                style="color:#2563eb;text-decoration:none;">{{ config('app.frontend_url', env('FRONTEND_URL')) }}</a>
        </div>
    </div>
</body>

</html>
