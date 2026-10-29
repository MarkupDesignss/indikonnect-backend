<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Update Phone Number</title>

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
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
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
            font-weight: bold;
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
            color: #374151;
        }

        label {
            display: block;
            font-size: 14px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        input,
        button,
        select {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .phone-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .phone-group select {
            width: 38%;
            margin: 8px 0;
        }

        .phone-group input {
            width: 62%;
            margin: 8px 0;
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
            color: #ffffff;
            border: none;
            font-weight: bold;
            font-size: 15px;
            padding: 12px 32px;
            border-radius: 6px;
            margin-top: 24px;
            transition: background 0.2s;
        }

        button:hover {
            background: #1d4ed8;
        }

        button:disabled {
            background: #93c5fd;
            cursor: not-allowed;
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

        .hint {
            font-size: 13px;
            color: #6b7280;
            margin-top: 8px;
        }
    </style>
</head>

<body>

    <div class="card">

        <!-- Header with logo and title -->
        <div class="card-header">
            <img src="https://www.markupdesigns.net/indikonnect/storage/logos/MmVFOudCiVU3Y8C0sf2gEEXna5jSKroCDj8xYMo0.png"
                alt="Logo">
            <h2>📩 Update Phone Number</h2>
        </div>

        <!-- Main content -->
        <div class="card-body">

            <h3>Update Your Phone Number</h3>

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            @php
                // Restore previous input (if any) into country code + number
                $oldPhone = old('phone', '');
                $oldCode = '+91';
                $oldNumber = '';

                if ($oldPhone) {
                    // Longest matching country code wins
                    $codes = ['+971', '+966', '+91', '+44', '+61', '+49', '+33', '+81', '+65', '+1'];
                    foreach ($codes as $code) {
                        if (str_starts_with($oldPhone, $code)) {
                            $oldCode = $code;
                            $oldNumber = substr($oldPhone, strlen($code));
                            break;
                        }
                    }
                }
            @endphp

            <form method="POST" action="{{ route('phone.send-otp', ['token' => $token]) }}" id="phoneUpdateForm">

                @csrf

                <label for="country_code">New Phone Number</label>

                <div class="phone-group">

                    <select id="country_code" required>
                        <option value="+91" @selected($oldCode === '+91')>India (+91)</option>
                        <option value="+1" @selected($oldCode === '+1')>USA/Canada (+1)</option>
                        <option value="+44" @selected($oldCode === '+44')>UK (+44)</option>
                        <option value="+61" @selected($oldCode === '+61')>Australia (+61)</option>
                        <option value="+971" @selected($oldCode === '+971')>UAE (+971)</option>
                        <option value="+966" @selected($oldCode === '+966')>Saudi Arabia (+966)</option>
                        <option value="+49" @selected($oldCode === '+49')>Germany (+49)</option>
                        <option value="+33" @selected($oldCode === '+33')>France (+33)</option>
                        <option value="+81" @selected($oldCode === '+81')>Japan (+81)</option>
                        <option value="+65" @selected($oldCode === '+65')>Singapore (+65)</option>
                    </select>

                    <input type="tel" id="phone_number" placeholder="Enter phone number" value="{{ $oldNumber }}"
                        required autocomplete="tel-national">

                </div>

                {{-- Combined country code + phone number --}}
                <input type="hidden" name="phone" id="phone" value="{{ $oldPhone }}">

                <button type="submit" id="submitBtn">Send OTP</button>

                <p class="hint">We'll send a 6-digit verification code to this number via SMS.</p>

            </form>

        </div>

        <!-- Footer -->
        <div class="card-footer">
            Automated message. Do not reply.<br>
            <a href="{{ config('app.frontend_url', env('FRONTEND_URL')) }}">
                {{ config('app.frontend_url', env('FRONTEND_URL')) }}
            </a>
        </div>

    </div>

    <script>
        const form = document.getElementById('phoneUpdateForm');
        const ccSelect = document.getElementById('country_code');
        const numInput = document.getElementById('phone_number');
        const hidden = document.getElementById('phone');
        const submitBtn = document.getElementById('submitBtn');

        form.addEventListener('submit', function(e) {
            // Strip spaces, dashes, parentheses
            let raw = numInput.value.replace(/[\s()-]/g, '');

            // Drop a single leading + if present
            raw = raw.replace(/^\+/, '');

            // Drop leading 0(s) — e.g. Indian users often type 09876543210
            raw = raw.replace(/^0+/, '');

            if (!/^\d{6,14}$/.test(raw)) {
                e.preventDefault();
                alert('Please enter a valid phone number (digits only, 6–14 digits).');
                numInput.focus();
                return;
            }

            const countryCode = ccSelect.value;
            hidden.value = countryCode + raw;

            // Prevent double-submits while the request is in flight
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending OTP…';
        });
    </script>

</body>

</html>
