<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Proforma Invoice - {{ $invoice->proforma_invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }

        .invoice {
            width: 794px;
            min-height: 1123px;
            background: white;
            margin: 0 auto;
            padding: 30px 40px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .watermark {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            color: rgba(30, 64, 175, 0.06);
            font-weight: 900;
            z-index: 0;
            pointer-events: none;
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #1E40AF;
            padding-bottom: 15px;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .header h1 {
            color: #1E40AF;
            font-size: 32px;
            letter-spacing: 3px;
        }

        .header .sub {
            color: #DC2626;
            font-size: 12px;
            font-weight: bold;
            margin-top: 5px;
        }

        .top-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            position: relative;
            z-index: 1;
        }

        .company-box {
            width: 48%;
        }

        .company-box .logo {
            font-size: 22px;
            font-weight: bold;
            color: #1E40AF;
            margin-bottom: 8px;
        }

        .company-box p {
            font-size: 11px;
            color: #475569;
            line-height: 1.6;
        }

        .meta-box {
            width: 48%;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            padding: 4px 0;
            border-bottom: 1px dashed #cbd5e1;
        }

        .meta-row .label {
            color: #64748B;
        }

        .meta-row .value {
            color: #1F2937;
            font-weight: 600;
        }

        .addresses {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            position: relative;
            z-index: 1;
        }

        .addr-box {
            flex: 1;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 12px;
            background: #F8FAFC;
        }

        .addr-box h3 {
            font-size: 11px;
            color: #1E40AF;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .addr-box p {
            font-size: 11px;
            color: #334155;
            line-height: 1.6;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        table.items th {
            background: #1E40AF;
            color: white;
            padding: 10px 8px;
            font-size: 11px;
            text-align: left;
        }

        table.items td {
            padding: 10px 8px;
            font-size: 11px;
            border-bottom: 1px solid #E2E8F0;
            color: #334155;
        }

        table.items tr:nth-child(even) {
            background: #F8FAFC;
        }

        .summary {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .summary-box {
            width: 350px;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 15px;
            background: #F8FAFC;
        }

        .sum-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 5px 0;
        }

        .sum-row.total {
            border-top: 2px solid #1E40AF;
            margin-top: 8px;
            padding-top: 10px;
            font-size: 16px;
            font-weight: bold;
            color: #1E40AF;
        }

        .words {
            background: #EFF6FF;
            border-left: 4px solid #1E40AF;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 11px;
            position: relative;
            z-index: 1;
        }

        .disclaimer {
            border: 2px solid #DC2626;
            border-radius: 6px;
            padding: 15px;
            background: #FEF2F2;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .disclaimer h3 {
            color: #DC2626;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .disclaimer p {
            font-size: 11px;
            color: #7F1D1D;
            line-height: 1.7;
        }

        .signature {
            text-align: right;
            margin-top: 40px;
            position: relative;
            z-index: 1;
        }

        .signature .line {
            border-top: 1px solid #334155;
            width: 200px;
            margin-left: auto;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 11px;
            color: #334155;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            color: #94A3B8;
            margin-top: 30px;
            border-top: 1px solid #E2E8F0;
            padding-top: 10px;
            position: relative;
            z-index: 1;
        }
    </style>
</head>

<body>
    <div class="invoice">
        <div class="watermark">PROFORMA</div>

        <div class="header">
            <h1>PROFORMA INVOICE</h1>
            <div class="sub">&#9888; This is NOT a Tax Invoice</div>
        </div>

        <div class="top-info">
            <div class="company-box">
                <div class="logo">{{ $invoice->seller_name ?? config('app.company_name', 'IndieKonnect') }}</div>
                <p><strong>{{ $invoice->seller_name ?? 'IndieKonnect Enterprises Pvt Ltd' }}</strong><br>
                    GSTIN: {{ $invoice->seller_gstin ?? config('app.company_gstin', '03XXXXX1234X1Z5') }}<br>
                    {!! nl2br(
                        e($invoice->seller_address ?? config('app.company_address', "5 New Lajpat Nagar, Ludhiana\nPunjab - 141001")),
                    ) !!}<br>
                    Email: {{ config('app.company_email', 'care@indiekonnect.com') }}</p>
            </div>
            <div class="meta-box">
                <div class="meta-row"><span class="label">Proforma No</span><span
                        class="value">{{ $invoice->proforma_invoice_number }}</span></div>
                <div class="meta-row"><span class="label">Proforma Date</span><span
                        class="value">{{ $invoice->issued_at?->format('d-M-Y') ?? now()->format('d-M-Y') }}</span>
                </div>
                <div class="meta-row"><span class="label">Valid Until</span><span class="value"
                        style="color:#DC2626">{{ ($invoice->issued_at ?? now())->addDays(7)->format('d-M-Y') }}</span>
                </div>
                <div class="meta-row"><span class="label">Order Ref</span><span
                        class="value">{{ $invoice->summary_snapshot['order_reference'] ?? 'N/A' }}</span></div>
                <div class="meta-row"><span class="label">Place of Supply</span><span
                        class="value">{{ $invoice->delivery_state ?? 'N/A' }}</span></div>
            </div>
        </div>

        <div class="addresses">
            <div class="addr-box">
                <h3>Billing Address</h3>
                <p>{!! nl2br(e($invoice->buyer_address ?? 'N/A')) !!}<br>
                    GSTIN: {{ $invoice->buyer_gstin ?? 'Unregistered' }}</p>
            </div>
            <div class="addr-box">
                <h3>Shipping Address</h3>
                <p>{!! nl2br(e($invoice->buyer_address ?? 'N/A')) !!}</p>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Description</th>
                    <th>HSN</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>Taxable</th>
                    <th>GST%</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->line_items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item['name'] ?? '-' }}</td>
                        <td>{{ $item['hsn_code'] ?? '-' }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>&#8377; {{ number_format($item['unit_price'], 2) }}</td>
                        <td>&#8377; {{ number_format($item['line_total'], 2) }}</td>
                        <td>{{ $item['gst_rate'] }}%</td>
                        <td>&#8377; {{ number_format($item['line_total'] + $item['gst_amount'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary">
            <div class="summary-box">
                <div class="sum-row"><span>Subtotal</span><span>&#8377;
                        {{ number_format($invoice->subtotal_before_redemption, 2) }}</span></div>

                @if ($invoice->coupon_discount > 0)
                    <div class="sum-row"><span>(-) Coupon ({{ $invoice->coupon_code }})</span><span>-&#8377;
                            {{ number_format($invoice->coupon_discount, 2) }}</span></div>
                @endif

                @if ($invoice->coin_redeemed > 0)
                    <div class="sum-row"><span>(-) Coins Redeemed</span><span>-&#8377;
                            {{ number_format($invoice->coin_redeemed, 2) }}</span></div>
                @endif

                @if ($invoice->shipping_charge > 0)
                    <div class="sum-row"><span>(+) Shipping Charge</span><span>+&#8377;
                            {{ number_format($invoice->shipping_charge, 2) }}</span></div>
                @endif

                <div class="sum-row"><span>Taxable Amount</span><span>&#8377;
                        {{ number_format($invoice->total_taxable, 2) }}</span></div>

                @if ($invoice->total_tax > 0)
                    <div class="sum-row"><span>GST</span><span>&#8377;
                            {{ number_format($invoice->total_tax, 2) }}</span></div>
                @endif

                <div class="sum-row total"><span>TOTAL PAYABLE</span><span>&#8377;
                        {{ number_format($invoice->total_payable, 2) }}</span></div>
            </div>
        </div>

        <div class="words">
            <strong>Amount in Words:</strong> {{ $amountInWords }}
        </div>

        <div class="disclaimer">
            <h3>&#9888; IMPORTANT DISCLAIMER</h3>
            <p>
                This is a <strong>PROFORMA INVOICE</strong> only. It is <strong>NOT a Tax Invoice</strong>.<br>
                This document is not a demand for payment of tax.<br>
                Goods will be dispatched only after receipt of payment.<br><br>
                &#128197; <strong>Validity:</strong> This proforma is valid till
                <strong>{{ ($invoice->issued_at ?? now())->addDays(7)->format('d-M-Y') }}</strong>.<br>
                &#128179; <strong>Payment Terms:</strong> 100% Advance Payment<br><br>
                &#127974; <strong>Bank Details:</strong><br>
                Account Name: {{ config('app.company_name', 'IndieKonnect Enterprises Pvt Ltd') }}<br>
                Account No: {{ config('app.bank_account_no', '1234567890123') }} &nbsp;|&nbsp; IFSC:
                {{ config('app.bank_ifsc', 'HDFC0001234') }} &nbsp;|&nbsp; Bank:
                {{ config('app.bank_name', 'HDFC Ludhiana') }}
            </p>
        </div>

        <div class="signature">
            For {{ $invoice->seller_name ?? config('app.company_name', 'IndieKonnect Enterprises Pvt Ltd') }}
            <div class="line">Authorized Signatory</div>
        </div>

        <div class="footer">
            This is a computer-generated Proforma Invoice | {{ config('app.company_email', 'care@indiekonnect.com') }}
            | {{ config('app.company_phone', '+91 98765 43210') }}
        </div>
    </div>
</body>

</html>
