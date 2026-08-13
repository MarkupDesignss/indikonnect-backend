<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .company {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .company-details {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .invoice-details {
            margin: 20px 0;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .table th,
        .table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .totals {
            float: right;
            width: 300px;
            margin-top: 20px;
        }

        .totals table {
            width: 100%;
        }

        .totals td {
            padding: 5px;
        }

        .summary-box {
            background: #f8f9fa;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .mt-20 {
            margin-top: 20px;
        }

        .border-top {
            border-top: 2px solid #000;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #666;
        }

        .clearfix {
            clear: both;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="company">{{ config('app.company_name', 'IndieKonnect') }}</div>
        <div class="company-details">
            {{ config('app.company_address', '') }}<br>
            GSTIN: {{ config('app.company_gstin', '') }}
        </div>
    </div>

    <div class="invoice-details">
        <table style="width: 100%;">
            <tr>
                <td>
                    <strong>Invoice Number:</strong> {{ $invoice->invoice_number }}<br>
                    <strong>Date:</strong> {{ $invoice->issued_at->format('d/m/Y H:i') }}
                </td>
                <td style="text-align: right;">
                    <strong>Order Reference:</strong> {{ $order->order_reference }}<br>
                    <strong>Order Type:</strong> {{ ucfirst($order->order_type) }}
                </td>
            </tr>
        </table>
    </div>

    <div class="summary-box">
        <table style="width: 100%;">
            <tr>
                <td style="width: 50%;">
                    <strong>Bill To:</strong><br>
                    {{ $invoice->buyer_name }}<br>
                    {{ $invoice->buyer_address }}<br>
                    {{ $invoice->delivery_state }}<br>
                    GSTIN: {{ $invoice->buyer_gstin ?: 'N/A' }}
                </td>
                @if ($order->deliveryAddress && $order->deliveryAddress->id != $order->billing_address_id)
                    <td style="width: 50%;">
                        <strong>Ship To:</strong><br>
                        {{ $order->deliveryAddress->address_line_1 }}<br>
                        {{ $order->deliveryAddress->city }}, {{ $order->deliveryAddress->state }}<br>
                        {{ $order->deliveryAddress->pincode }}
                    </td>
                @endif
            </tr>
        </table>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>HSN/SAC</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Taxable Value</th>
                <th style="text-align: center;">GST%</th>
                <th style="text-align: right;">CGST</th>
                <th style="text-align: right;">SGST</th>
                <th style="text-align: right;">IGST</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lineItems as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item['product_name'] }}</td>
                    <td>{{ $item['product_code'] ?? '-' }}</td>
                    <td class="text-center">{{ $item['quantity'] }}</td>
                    <td class="text-right">₹{{ number_format($item['unit_price'], 2) }}</td>
                    <td class="text-right">₹{{ number_format($item['taxable_value'], 2) }}</td>
                    <td class="text-center">{{ $item['gst_rate'] }}%</td>
                    <td class="text-right">₹{{ number_format($item['cgst'], 2) }}</td>
                    <td class="text-right">₹{{ number_format($item['sgst'], 2) }}</td>
                    <td class="text-right">₹{{ number_format($item['igst'], 2) }}</td>
                    <td class="text-right">₹{{ number_format($item['line_total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td>Subtotal:</td>
                <td class="text-right">₹{{ number_format($invoice->subtotal_before_redemption, 2) }}</td>
            </tr>
            @if ($invoice->coupon_discount > 0)
                <tr>
                    <td>Coupon Discount ({{ $invoice->coupon_code }}):</td>
                    <td class="text-right">-₹{{ number_format($invoice->coupon_discount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td>Subtotal After Discount:</td>
                <td class="text-right">₹{{ number_format($order->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td>Total Tax:</td>
                <td class="text-right">₹{{ number_format($invoice->total_tax, 2) }}</td>
            </tr>
            @if ($invoice->shipping_charge > 0)
                <tr>
                    <td>Shipping Charge:</td>
                    <td class="text-right">₹{{ number_format($invoice->shipping_charge, 2) }}</td>
                </tr>
            @endif
            @if ($invoice->coin_redeemed > 0)
                <tr>
                    <td>Coins Redeemed:</td>
                    <td class="text-right">-₹{{ number_format($invoice->coin_redeemed, 2) }}</td>
                </tr>
            @endif
            <tr class="border-top">
                <td><strong>Total Payable:</strong></td>
                <td class="text-right"><strong>₹{{ number_format($order->total_payable, 2) }}</strong></td>
            </tr>
        </table>
    </div>

    <div class="clearfix"></div>

    <div class="footer">
        <p>Thank you for your business!</p>
        <p>This is a system-generated invoice. For any queries, please contact us.</p>
        <p>Invoice generated on: {{ $invoice->issued_at->format('d/m/Y H:i:s') }}</p>
    </div>
</body>

</html>
