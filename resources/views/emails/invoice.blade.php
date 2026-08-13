<!DOCTYPE html>
<html>

<head>
    <title>Invoice for Order #{{ $order->order_reference }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
        }

        .summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }

        .amount {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Thank you for your order!</h2>
            <p>Dear {{ $order->user->full_name }},</p>
            <p>Your order has been confirmed. Please find your invoice attached to this email.</p>
        </div>

        <div class="summary">
            <h3>Order Summary</h3>
            <p><strong>Order Reference:</strong> {{ $order->order_reference }}</p>
            <p><strong>Order Date:</strong> {{ $order->created_at->format('d/m/Y H:i') }}</p>
            <p><strong>Order Status:</strong> {{ ucfirst($order->status) }}</p>
            <p><strong>Total Amount:</strong> <span class="amount">₹{{ number_format($order->total_payable, 2) }}</span>
            </p>

            @if ($order->coin_redeemed > 0)
                <p><strong>Coins Redeemed:</strong> ₹{{ number_format($order->coin_redeemed, 2) }}</p>
            @endif

            @if ($order->coupon_code)
                <p><strong>Coupon Applied:</strong> {{ $order->coupon_code }}</p>
                <p><strong>Discount:</strong> ₹{{ number_format($order->coupon_discount, 2) }}</p>
            @endif
        </div>

        <p><strong>Invoice Number:</strong> {{ $invoice->invoice_number }}</p>
        <p>You can view your order details in your account dashboard.</p>

        <p>If you have any questions, please don't hesitate to contact us.</p>

        <p>Best regards,<br>
            <strong>{{ config('app.company_name', 'IndieKonnect') }}</strong>
        </p>

        <div class="footer">
            <p>This is a system-generated email. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.company_name', 'IndieKonnect') }}. All rights reserved.</p>
        </div>
    </div>
</body>

</html>
