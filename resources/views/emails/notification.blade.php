<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 30px 25px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 500;
            color: #333;
            margin-bottom: 15px;
        }
        .message {
            color: #555;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .message p {
            margin: 10px 0;
        }
        .message strong {
            color: #333;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 25px;
            text-align: center;
            color: #888;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            text-decoration: none !important;
            border-radius: 5px;
            margin-top: 15px;
            font-weight: 500;
        }
        .button:hover {
            opacity: 0.9;
        }
        .divider {
            border: none;
            border-top: 2px solid #e9ecef;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>{{ config('app.name') }}</h1>
            <p style="margin: 5px 0 0; opacity: 0.9;">Notification</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Hello {{ $user->full_name ?? $user->name ?? 'Customer' }}!
            </div>

            <div class="message">
                {!! nl2br(e($body)) !!}
            </div>

            @if(isset($order) && isset($order->id))
                <div style="text-align: center;">
                    <a href="{{ route('user.orders.show', $order->id) }}" class="button">
                        View Order Details
                    </a>
                </div>
            @endif

            <hr class="divider">

            <div style="color: #888; font-size: 13px;">
                <p>If you have any questions, please contact our support team.</p>
                <p>Thank you for choosing {{ config('app.name') }}!</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
            <p>
                <a href="{{ url('/') }}">Visit our website</a>
            </p>
        </div>
    </div>
</body>
</html>
