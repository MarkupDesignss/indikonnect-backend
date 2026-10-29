<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Verify OTP</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f5f7;
            margin: 0;
            padding: 40px 16px;
        }

        .card {
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
            max-width: 520px;
            margin: 0 auto;
        }

        .card-header {
            background: #1e3a8a;
            color: #fff;
            padding: 18px 24px;
            text-align: center;
        }

        .card-header img {
            max-height: 44px;
            display: block;
            margin: 0 auto 10px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .card-body {
            padding: 32px 40px;
            color: #374151;
            font-size: 15px;
            line-height: 1.6;
        }

        .card-body h3 {
            margin-top: 0;
            font-size: 18px;
        }

        label {
            display: block;
            font-size: 14px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        input,
        button {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
        }

        input#otp {
            text-align: center;
            letter-spacing: 8px;
            font-size: 22px;
            font-weight: bold;
        }

        .error {
            color: #dc2626;
            font-size: 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }

        button {
            cursor: pointer;
            background: #2563eb;
            color: #fff;
            border: none;
            font-weight: bold;
            font-size: 15px;
            padding: 12px 32px;
            border-radius: 6px;
            margin-top: 24px;
            transition: background .2s;
        }

        button:hover {
            background: #1d4ed8;
        }

        .card-footer {
            background: #f9fafb;
            padding: 14px 24px;
            font-size: 12px;
            color: #888;
            text-align: center;
        }

        .card-footer a {
            color: #2563eb;
            text-decoration: none;
        }

        .back-link {
            display: inline-block;
            margin-top: 12px;
            font-size: 13px;
            color: #2563eb;
            text-decoration: none;
        }
    </style>
</head>

<body>

    <div class="card">
        <div class="card-header">
            <img src="https://www.markupdesigns.net/indikonnect/storage/logos/MmVFOudCiVU3Y8C0sf2gEEXna5jSKroCDj8xYMo0.png"
                alt="Logo">
            <h2>🔐 Verify OTP</h2>
        </div>

        <div class="card-body">

            <h3>Enter Verification Code</h3>
            <p style="font-size:14px;color:#6b7280;margin-top:0">
                We've sent a 6-digit code to <strong>{{ $phone }}</strong>.
            </p>

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('phone.verify-otp', ['token' => $token]) }}">
                @csrf

                <label for="otp">Verification Code</label>
                <input type="text" id="otp" name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6"
                    placeholder="••••••" required autocomplete="one-time-code" autofocus>

                <button type="submit">Verify &amp; Update</button>
            </form>

            <a href="{{ route('phone.form', ['token' => $token]) }}" class="back-link">← Use a different number</a>

        </div>

        <div class="card-footer">
            Automated message. Do not reply.<br>
            <a href="{{ config('app.frontend_url', env('FRONTEND_URL')) }}">
                {{ config('app.frontend_url', env('FRONTEND_URL')) }}
            </a>
        </div>
    </div>

</body>

</html>
