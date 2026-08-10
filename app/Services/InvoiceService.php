<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function generateInvoice(Order $order): Invoice
    {
        $invoiceNumber = $this->generateInvoiceNumber();

        $lineItems = [];
        $totalTaxable = 0;
        $totalCgst = 0;
        $totalSgst = 0;
        $totalIgst = 0;

        $taxBreakdown = json_decode($order->tax_breakdown, true) ?? [];

        foreach ($order->lines as $index => $line) {
            $taxable = $line->unit_price * $line->quantity;
            $totalTaxable += $taxable;

            $itemTax = $taxBreakdown[$index] ?? ['cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $totalCgst += $itemTax['cgst'] ?? 0;
            $totalSgst += $itemTax['sgst'] ?? 0;
            $totalIgst += $itemTax['igst'] ?? 0;

            $lineItems[] = [
                'product_name' => $line->product->name,
                'product_code' => $line->product->product_code,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'taxable_value' => $taxable,
                'gst_rate' => $line->gst_rate,
                'cgst' => $itemTax['cgst'] ?? 0,
                'sgst' => $itemTax['sgst'] ?? 0,
                'igst' => $itemTax['igst'] ?? 0,
                'gst_amount' => $line->gst_amount,
                'line_total' => $line->line_total,
            ];
        }

        $totalTax = $totalCgst + $totalSgst + $totalIgst;
        $totalAmount = $totalTaxable + $totalTax;

        return Invoice::create([
            'invoice_number' => $invoiceNumber,
            'order_id' => $order->id,
            'seller_name' => config('app.company_name', 'IndieKonnect'),
            'seller_gstin' => config('app.company_gstin', ''),
            'seller_address' => config('app.company_address', ''),
            'buyer_name' => $order->user->full_name,
            'buyer_gstin' => $order->user->distributorProfile?->gst_number ?? '',
            'buyer_address' => $order->deliveryAddress->address_line_1,
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
    }

    protected function generateInvoiceNumber(): string
    {
        $last = Invoice::orderBy('id', 'desc')->first();
        $next = $last ? (int) substr($last->invoice_number, 4) + 1 : 1001;
        return 'INV-' . str_pad($next, 6, '0', STR_PAD_LEFT);
    }
}