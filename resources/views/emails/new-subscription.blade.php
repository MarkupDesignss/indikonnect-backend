<!-- resources/views/emails/new-subscription.blade.php -->
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Subscription</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: #4CAF50;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }

        .content {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 0 0 5px 5px;
            border: 1px solid #ddd;
            border-top: none;
        }

        .info-box {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #4CAF50;
        }

        .label {
            font-weight: bold;
            color: #555;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #888;
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>📧 New Newsletter Subscription</h1>
    </div>
    <div class="content">
        <p>Hello Admin,</p>

        <p>A new user has subscribed to your newsletter:</p>

        <div class="info-box">
            <p><span class="label">Email:</span> <strong>{{ $email }}</strong></p>
            <p><span class="label">Subscribed At:</span> {{ $subscribedAt->format('F j, Y, g:i A') }}</p>
            <p><span class="label">Status:</span> <span class="badge">Active</span></p>
        </div>

        <p>You can manage all subscribers from the admin panel.</p>

        <hr>

        <p style="font-size: 14px; color: #666;">
            <strong>Total Subscribers:</strong> {{ \App\Models\Subscriber::count() }}
        </p>

        <div class="footer">
            <p>This is an automated notification from {{ config('app.name') }}</p>
            <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
