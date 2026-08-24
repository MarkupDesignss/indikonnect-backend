<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $invoice;
    public $pdfContent;
    public $templateData;

    public function __construct($order, $invoice, $pdfContent)
    {
        $this->order = $order;
        $this->invoice = $invoice;
        $this->pdfContent = $pdfContent;
        $this->templateData = $this->prepareTemplateData();
    }

    /**
     * Prepare template data for notification
     */
    protected function prepareTemplateData()
    {
        $user = $this->order->user;

        // Get order items for template
        $orderItems = [];
        foreach ($this->order->lines as $line) {
            $product = $line->product;
            $orderItems[] = [
                'product_name' => $product->name ?? 'Product',
                'product_code' => $product->product_code ?? '',
                'quantity' => $line->quantity,
                'unit_price' => number_format($line->unit_price, 2),
                'line_total' => number_format($line->line_total, 2),
            ];
        }

        // Get delivery address
        $address = $this->order->deliveryAddress;
        $deliveryAddress = 'No address provided';
        if ($address) {
            $deliveryAddress = implode(', ', array_filter([
                $address->address_line1,
                $address->address_line2,
                $address->city,
                $address->state,
                $address->postal_code,
                $address->country,
            ]));
        }

        return [
            'order_reference' => $this->order->order_reference,
            'order_date' => $this->order->created_at->format('d/m/Y H:i'),
            'order_status' => ucfirst($this->order->status),
            'total_payable' => number_format($this->order->total_payable, 2),
            'customer_name' => $user->full_name ?? $user->name ?? 'Customer',
            'customer_email' => $user->email ?? '',
            'customer_phone' => $user->phone ?? '',
            'coin_redeemed' => $this->order->coin_redeemed ?? 0,
            'coin_redeemed_amount' => number_format($this->order->coin_redeemed_amount ?? 0, 2),
            'coupon_code' => $this->order->coupon_code ?? '',
            'coupon_discount' => number_format($this->order->coupon_discount ?? 0, 2),
            'invoice_number' => $this->invoice->invoice_number ?? '',
            'subtotal' => number_format($this->order->subtotal, 2),
            'total_gst' => number_format($this->order->total_gst, 2),
            'shipping_charge' => number_format($this->order->shipping_charge, 2),
            'order_items' => $orderItems,
            'payment_gateway' => $this->order->payment_gateway ?? 'Razorpay',
            'transaction_id' => $this->order->gateway_transaction_id ?? '',
            'delivery_address' => $deliveryAddress,
            'order_url' => url('/user/orders/' . $this->order->id),
            'company_name' => config('app.company_name', 'IndieKonnect'),
            'company_email' => config('mail.from.address'),
            'company_phone' => config('app.company_phone', ''),
            'year' => now()->year,
        ];
    }

    /**
     * Get template from database
     */
    protected function getTemplate()
    {
        try {
            // Get template from database
            $template = NotificationTemplate::where('event_type', 'order_confirmed')
                ->where('channel', 'mail')
                ->where('is_active', true)
                ->first();

            if ($template) {
                return [
                    'subject' => $template->subject,
                    'body' => $template->body
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Could not fetch template from database: ' . $e->getMessage());
        }

        // Fallback template if database template doesn't exist
        return [
            'subject' => 'Order confirmation #{{ order_reference }}',
            'body' => $this->getFallbackBody()
        ];
    }

    /**
     * Get fallback template body
     */
    protected function getFallbackBody()
    {
        return <<<'HTML'
    <!DOCTYPE html>
    <html>
    <head>
        <title>Order confirmation #{{ order_reference }}</title>
        <style>
            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
            .summary { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .items-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            .items-table th { background: #f8f9fa; padding: 10px; text-align: left; }
            .items-table td { padding: 10px; border-bottom: 1px solid #eee; }
            .amount { font-size: 18px; font-weight: bold; color: #28a745; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Thank you for your order!</h2>
                <p>Dear {{ customer_name }},</p>
                <p>Your order has been confirmed successfully. Please find your order details below.</p>
            </div>

            <div class="summary">
                <h3>Order Summary</h3>
                <p><strong>Order Reference:</strong> {{ order_reference }}</p>
                <p><strong>Order Date:</strong> {{ order_date }}</p>
                <p><strong>Order Status:</strong> {{ order_status }}</p>
                <p><strong>Total Amount:</strong> <span class="amount">₹{{ total_payable }}</span></p>

                @if(coin_redeemed > 0)
                <p><strong>Coins Redeemed:</strong> ₹{{ coin_redeemed_amount }}</p>
                @endif

                @if(coupon_code)
                <p><strong>Coupon Applied:</strong> {{ coupon_code }}</p>
                <p><strong>Discount:</strong> ₹{{ coupon_discount }}</p>
                @endif
            </div>

            <h3>Order Items</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(order_items as $item)
                    <tr>
                        <td>{{ $item['product_name'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>₹{{ $item['unit_price'] }}</td>
                        <td>₹{{ $item['line_total'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <p>If you have any questions, please don't hesitate to contact us.</p>

            <p>Best regards,<br>
                <strong>{{ company_name }}</strong>
            </p>

            <div class="footer">
                <p>This is a system-generated email. Please do not reply to this email.</p>
                <p>&copy; {{ year }} {{ company_name }}. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
HTML;
    }

    /**
     * Replace template placeholders with actual data
     */
    protected function replacePlaceholders($content)
    {
        $placeholders = [
            '{{ order_reference }}' => $this->templateData['order_reference'],
            '{{ order_date }}' => $this->templateData['order_date'],
            '{{ order_status }}' => $this->templateData['order_status'],
            '{{ total_payable }}' => $this->templateData['total_payable'],
            '{{ customer_name }}' => $this->templateData['customer_name'],
            '{{ customer_email }}' => $this->templateData['customer_email'],
            '{{ customer_phone }}' => $this->templateData['customer_phone'],
            '{{ coin_redeemed }}' => $this->templateData['coin_redeemed'],
            '{{ coin_redeemed_amount }}' => $this->templateData['coin_redeemed_amount'],
            '{{ coupon_code }}' => $this->templateData['coupon_code'],
            '{{ coupon_discount }}' => $this->templateData['coupon_discount'],
            '{{ invoice_number }}' => $this->templateData['invoice_number'],
            '{{ subtotal }}' => $this->templateData['subtotal'],
            '{{ total_gst }}' => $this->templateData['total_gst'],
            '{{ shipping_charge }}' => $this->templateData['shipping_charge'],
            '{{ payment_gateway }}' => $this->templateData['payment_gateway'],
            '{{ transaction_id }}' => $this->templateData['transaction_id'],
            '{{ delivery_address }}' => $this->templateData['delivery_address'],
            '{{ order_url }}' => $this->templateData['order_url'],
            '{{ company_name }}' => $this->templateData['company_name'],
            '{{ company_email }}' => $this->templateData['company_email'],
            '{{ company_phone }}' => $this->templateData['company_phone'],
            '{{ year }}' => $this->templateData['year'],
        ];

        // Replace single placeholders
        foreach ($placeholders as $key => $value) {
            $content = str_replace($key, $value, $content);
        }

        // Handle order items loop
        $content = $this->replaceOrderItems($content);

        return $content;
    }

    /**
     * Replace order items loop
     */
    protected function replaceOrderItems($content)
    {
        $pattern = '/@foreach\(order_items as \$item\)(.*?)@endforeach/s';
        preg_match($pattern, $content, $matches);

        if (empty($matches)) {
            return $content;
        }

        $loopTemplate = $matches[1];
        $itemsHtml = '';

        foreach ($this->templateData['order_items'] as $item) {
            $itemHtml = $loopTemplate;
            $itemPlaceholders = [
                '{{ $item[\'product_name\'] }}' => $item['product_name'],
                '{{ $item[\'product_code\'] }}' => $item['product_code'] ?? '',
                '{{ $item[\'quantity\'] }}' => $item['quantity'],
                '{{ $item[\'unit_price\'] }}' => $item['unit_price'],
                '{{ $item[\'line_total\'] }}' => $item['line_total'],
            ];

            foreach ($itemPlaceholders as $key => $value) {
                $itemHtml = str_replace($key, $value, $itemHtml);
            }

            $itemsHtml .= $itemHtml;
        }

        return str_replace($matches[0], $itemsHtml, $content);
    }

    /**
     * Build the email
     */
    public function build()
    {
        // Get template from database
        $template = $this->getTemplate();

        // Replace subject placeholders
        $subject = $this->replacePlaceholders($template['subject']);

        // Replace body placeholders
        $body = $this->replacePlaceholders($template['body']);

        return $this->subject($subject)
            ->html($body)
            ->attachData($this->pdfContent, 'Order_' . $this->order->order_reference . '.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}
