<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Return Initiated</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; color: #333; }
        .container { max-width: 600px; margin: auto; background: #fff; border-radius: 8px; overflow: hidden; }
        .header { background: #4f46e5; color: #fff; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { padding: 24px; }
        .content p { line-height: 1.6; }
        .box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin: 16px 0; }
        .box h3 { margin-top: 0; font-size: 16px; color: #4f46e5; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
        th { background: #f3f4f6; }
        .total { font-weight: bold; color: #111827; }
        .footer { background: #f3f4f6; text-align: center; padding: 16px; font-size: 12px; color: #6b7280; }
        .badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 12px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Return Request Initiated</h1>
        </div>

        <div class="content">
            <p>Hi <strong>{{ $userName }}</strong>,</p>

            <p>
                Aapka return request successfully initiate ho gaya hai for order
                <strong>#{{ $returnOrder->order->order_reference }}</strong>.
                Hamari team ise review karegi aur aapko update degi.
            </p>

            {{-- Return Method Details --}}
            <div class="box">
                <h3>Return Method</h3>
                <p>
                    <strong>Method:</strong>
                    {{ ucfirst($returnMethod) }}
                    <span class="badge">{{ ucfirst($returnMethod) }}</span>
                </p>

                @if($returnMethod === 'courier')
                    <p><strong>Courier Partner:</strong> {{ $courier ?? 'N/A' }}</p>
                    <p><strong>Tracking ID:</strong> {{ $trackingId ?? 'N/A' }}</p>
                @else
                    <p>Hamari team aapke doorstep se pickup arrange karegi.</p>
                @endif
            </div>

            {{-- Items --}}
            <div class="box">
                <h3>Items Being Returned</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($returnItems as $item)
                            <tr>
                                <td>{{ $item['product_name'] }}</td>
                                <td>{{ $item['quantity'] }}</td>
                                <td>₹{{ number_format($item['line_total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Refund Summary --}}
            <div class="box">
                <h3>Refund Summary</h3>
                <table>
                    <tr>
                        <td>Subtotal</td>
                        <td>₹{{ number_format($refundDetails['subtotal'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>Tax (GST)</td>
                        <td>₹{{ number_format($refundDetails['tax'], 2) }}</td>
                    </tr>
                    <tr>
                        <td>Shipping Refund</td>
                        <td>₹{{ number_format($refundDetails['shipping'], 2) }}</td>
                    </tr>
                    <tr class="total">
                        <td>Total Refund</td>
                        <td>₹{{ number_format($refundDetails['total'], 2) }}</td>
                    </tr>
                </table>
            </div>

            <p>
                Return ID: <strong>#{{ $returnOrder->id }}</strong><br>
                Status: <strong>{{ ucfirst($returnOrder->status) }}</strong>
            </p>

            <p>Agar aapne ye request nahi ki, to turant support se contact karein.</p>

            <p>Thanks,<br>{{ config('app.name') }} Team</p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
