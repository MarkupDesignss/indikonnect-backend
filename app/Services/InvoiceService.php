<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * FR-CO-007: Generate tax invoice for order
     */
    public function generateInvoice(Order $order): Invoice
    {
        $invoiceNumber = $this->generateInvoiceNumber();

        // Get line items with GST breakdown
        $lineItems = [];
        $totalCgst = 0;
        $totalSgst = 0;
        $totalIgst = 0;
        $totalTaxable = 0;

        foreach ($order->lines as $line) {
            $taxableValue = $line->unit_price * $line->quantity;
            $gstAmount = $line->gst_amount ?? 0;
            $totalTaxable += $taxableValue;

            // Extract CGST/SGST/IGST from tax breakdown
            $taxBreakdown = json_decode($order->tax_breakdown, true);
            $itemTax = $taxBreakdown[$line->id] ?? ['cgst' => 0, 'sgst' => 0, 'igst' => 0];

            $totalCgst += $itemTax['cgst'] ?? 0;
            $totalSgst += $itemTax['sgst'] ?? 0;
            $totalIgst += $itemTax['igst'] ?? 0;

            $lineItems[] = [
                'product_name' => $line->product->name,
                'product_code' => $line->product->product_code,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'taxable_value' => $taxableValue,
                'gst_rate' => $line->gst_rate,
                'cgst' => $itemTax['cgst'] ?? 0,
                'sgst' => $itemTax['sgst'] ?? 0,
                'igst' => $itemTax['igst'] ?? 0,
                'gst_amount' => $gstAmount,
                'line_total' => $line->line_total,
            ];
        }

        $totalTax = $totalCgst + $totalSgst + $totalIgst;
        $totalAmount = $totalTaxable + $totalTax;

        $invoice = Invoice::create([
            'invoice_number' => $invoiceNumber,
            'order_id' => $order->id,
            'seller_name' => config('app.company_name', 'IndieKonnect'),
            'seller_gstin' => config('app.company_gstin', ''),
            'seller_address' => config('app.company_address', ''),
            'buyer_name' => $order->user->full_name,
            'buyer_gstin' => $order->user->distributorProfile?->gst_number ?? null,
            'buyer_address' => $order->deliveryAddress->address_line_1 . ', ' . $order->deliveryAddress->city,
            'delivery_state' => $order->deliveryAddress->state,
            'line_items' => json_encode($lineItems),
            'subtotal_before_redemption' => $order->subtotal + $order->coin_redeemed,
            'coin_redeemed' => $order->coin_redeemed,
            'total_taxable' => $totalTaxable,
            'total_cgst' => $totalCgst,
            'total_sgst' => $totalSgst,
            'total_igst' => $totalIgst,
            'total_tax' => $totalTax,
            'total' => $totalAmount,
            'issued_at' => now(),
        ]);

        return $invoice;
    }

    /**
     * Generate sequential invoice number (FR-CO-007)
     */
    protected function generateInvoiceNumber(): string
    {
        $lastInvoice = Invoice::orderBy('id', 'desc')->first();
        $nextNumber = $lastInvoice ? (int) substr($lastInvoice->invoice_number, 4) + 1 : 1001;
        return 'INV-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }
}