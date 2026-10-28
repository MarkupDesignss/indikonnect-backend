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

            <form method="POST" action="{{ url("/phone-update/{$token}") }}" id="phoneUpdateForm">

                @csrf

                <label for="country_code">New Phone Number</label>

                <div class="phone-group">

                    <select id="country_code" required>
                        <option value="+91" selected>India (+91)</option>
                        <option value="+1">USA/Canada (+1)</option>
                        <option value="+44">UK (+44)</option>
                        <option value="+61">Australia (+61)</option>
                        <option value="+971">UAE (+971)</option>
                        <option value="+966">Saudi Arabia (+966)</option>
                        <option value="+49">Germany (+49)</option>
                        <option value="+33">France (+33)</option>
                        <option value="+81">Japan (+81)</option>
                        <option value="+65">Singapore (+65)</option>
                    </select>

                    <input type="tel" id="phone_number" placeholder="Enter phone number" value="{{ old('phone') }}"
                        required autocomplete="tel-national">

                </div>

                {{-- Combined country code + phone number --}}
                <input type="hidden" name="phone" id="phone">

                <button type="submit">Update</button>

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
        document.getElementById('phoneUpdateForm').addEventListener('submit', function(e) {
            const countryCode = document.getElementById('country_code').value;
            const phoneNumber = document.getElementById('phone_number').value
                .replace(/[\s()-]/g, '');

            if (!/^\d+$/.test(phoneNumber)) {
                e.preventDefault();
                alert('Please enter a valid phone number using digits only.');
                return;
            }

            // Remove an optional leading + from the entered phone number.
            const cleanPhone = phoneNumber.replace(/^\+/, '');

            // Combine country code and phone number into a single field.
            document.getElementById('phone').value =
                countryCode + cleanPhone;
        });
    </script>

</body>

</html>
