<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $user->name }} - Distributor Profile</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 40px;
        }
        .card {
            max-width: 600px;
            margin: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 30px;
        }
        .card h1 {
            margin-top: 0;
            color: #333;
        }
        .row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .row .label {
            font-weight: bold;
            width: 180px;
            color: #555;
        }
        .row .value {
            color: #222;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $user->name }}</h1>

        <div class="row">
            <div class="label">Email:</div>
            <div class="value">{{ $user->email ?? 'N/A' }}</div>
        </div>

        <div class="row">
            <div class="label">Phone:</div>
            <div class="value">{{ $user->phone ?? 'N/A' }}</div>
        </div>

        <div class="row">
            <div class="label">Account Type:</div>
            <div class="value">{{ ucfirst($user->account_type) }}</div>
        </div>

        @if($user->distributorProfile)
            <div class="row">
                <div class="label">Business Name:</div>
                <div class="value">{{ $user->distributorProfile->business_name ?? 'N/A' }}</div>
            </div>
            <div class="row">
                <div class="label">Business Address:</div>
                <div class="value">{{ $user->distributorProfile->business_address ?? 'N/A' }}</div>
            </div>
            <div class="row">
                <div class="label">GST Number:</div>
                <div class="value">{{ $user->distributorProfile->gst_number ?? 'N/A' }}</div>
            </div>
        @endif
    </div>
</body>
</html>
