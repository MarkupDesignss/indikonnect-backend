<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    // public function generateInvoice(Order $order): Invoice
    // {
    //     $invoiceNumber = $this->generateInvoiceNumber();

    //     $lineItems = [];
    //     $totalTaxable = 0;
    //     $totalCgst = 0;
    //     $totalSgst = 0;
    //     $totalIgst = 0;

    //     $taxBreakdown = json_decode($order->tax_breakdown, true) ?? [];

    //     foreach ($order->lines as $index => $line) {
    //         $taxable = $line->unit_price * $line->quantity;
    //         $totalTaxable += $taxable;

    //         $itemTax = $taxBreakdown[$index] ?? ['cgst' => 0, 'sgst' => 0, 'igst' => 0];
    //         $totalCgst += $itemTax['cgst'] ?? 0;
    //         $totalSgst += $itemTax['sgst'] ?? 0;
    //         $totalIgst += $itemTax['igst'] ?? 0;

    //         $lineItems[] = [
    //             'product_name' => $line->product->name,
    //             'product_code' => $line->product->product_code,
    //             'quantity' => $line->quantity,
    //             'unit_price' => $line->unit_price,
    //             'taxable_value' => $taxable,
    //             'gst_rate' => $line->gst_rate,
    //             'cgst' => $itemTax['cgst'] ?? 0,
    //             'sgst' => $itemTax['sgst'] ?? 0,
    //             'igst' => $itemTax['igst'] ?? 0,
    //             'gst_amount' => $line->gst_amount,
    //             'line_total' => $line->line_total,
    //         ];
    //     }

    //     $totalTax = $totalCgst + $totalSgst + $totalIgst;
    //     $totalAmount = $totalTaxable + $totalTax;

    //     return Invoice::create([
    //         'invoice_number' => $invoiceNumber,
    //         'order_id' => $order->id,
    //         'seller_name' => config('app.company_name', 'IndieKonnect'),
    //         'seller_gstin' => config('app.company_gstin', ''),
    //         'seller_address' => config('app.company_address', ''),
    //         'buyer_name' => $order->user->full_name,
    //         'buyer_gstin' => $order->user->distributorProfile?->gst_number ?? '',
    //         'buyer_address' => $order->deliveryAddress->address_line_1,
    //         'delivery_state' => $order->deliveryAddress->state,
    //         'line_items' => json_encode($lineItems),
    //         'subtotal_before_redemption' => $order->subtotal + $order->coin_redeemed,
    //         'coin_redeemed' => $order->coin_redeemed,
    //         'total_taxable' => $totalTaxable,
    //         'total_cgst' => $totalCgst,
    //         'total_sgst' => $totalSgst,
    //         'total_igst' => $totalIgst,
    //         'total_tax' => $totalTax,
    //         'total' => $totalAmount,
    //         'issued_at' => now(),
    //     ]);
    // }

    // protected function generateInvoiceNumber(): string
    // {
    //     $last = Invoice::orderBy('id', 'desc')->first();
    //     $next = $last ? (int) substr($last->invoice_number, 4) + 1 : 1001;
    //     return 'INV-' . str_pad($next, 6, '0', STR_PAD_LEFT);
    // }

    public function generateInvoice(Order $order): Invoice
    {
        $invoiceNumber = $this->generateInvoiceNumber();

        // Use the stored summary data
        $summaryData = json_decode($order->summary_data, true);

        if (!$summaryData) {
            // Fallback: Recalculate from order lines
            $summaryData = $this->recalculateFromOrderLines($order);
        }

        $lineItems = [];
        $totalTaxable = 0;
        $totalCgst = 0;
        $totalSgst = 0;
        $totalIgst = 0;

        $items = $summaryData['items'] ?? [];
        $taxBreakdown = json_decode($order->tax_breakdown, true) ?? [];

        foreach ($items as $index => $item) {
            $taxableValue = $item['taxable_value'] ?? ($item['unit_price'] * $item['quantity']);
            $totalTaxable += $taxableValue;

            $cgst = $item['cgst'] ?? 0;
            $sgst = $item['sgst'] ?? 0;
            $igst = $item['igst'] ?? 0;

            $totalCgst += $cgst;
            $totalSgst += $sgst;
            $totalIgst += $igst;

            $lineItems[] = [
                'product_name' => $item['product_name'],
                'product_code' => $item['product_code'] ?? '',
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'taxable_value' => $taxableValue,
                'gst_rate' => $item['gst_rate'],
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'gst_amount' => $item['total_tax'],
                'line_total' => $item['line_total'],
            ];
        }

        $totalTax = $totalCgst + $totalSgst + $totalIgst;
        $totalAmount = $totalTaxable + $totalTax;

        // Calculate subtotal before any discounts/redemptions
        $subtotalBeforeRedemption = $order->subtotal + $order->coin_redeemed + ($order->coupon_discount ?? 0);

        $invoice = Invoice::create([
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
            'subtotal_before_redemption' => $subtotalBeforeRedemption,
            'subtotal_after_discount' => $order->subtotal,
            'coin_redeemed' => $order->coin_redeemed,
            'coupon_discount' => $order->coupon_discount ?? 0,
            'coupon_code' => $order->coupon_code,
            'shipping_charge' => $order->shipping_charge ?? 0,
            'total_taxable' => $totalTaxable,
            'total_cgst' => $totalCgst,
            'total_sgst' => $totalSgst,
            'total_igst' => $totalIgst,
            'total_tax' => $totalTax,
            'total' => $totalAmount,
            'total_payable' => $order->total_payable,
            'issued_at' => now(),
            'summary_snapshot' => json_encode($summaryData),
        ]);

        return $invoice;
    }

    protected function generateInvoiceNumber(): string
    {
        $last = Invoice::orderBy('id', 'desc')->first();
        $next = $last ? (int) substr($last->invoice_number, 4) + 1 : 1001;
        return 'INV-' . str_pad($next, 6, '0', STR_PAD_LEFT);
    }

    protected function recalculateFromOrderLines(Order $order): array
    {
        $items = [];
        $subtotal = 0;
        $totalTax = 0;
        $taxBreakdown = [];

        foreach ($order->lines as $line) {
            $taxData = json_decode($line->tax_data, true) ?? [];
            $cgst = $taxData['cgst'] ?? 0;
            $sgst = $taxData['sgst'] ?? 0;
            $igst = $taxData['igst'] ?? 0;
            $taxableValue = $taxData['taxable_value'] ?? ($line->unit_price * $line->quantity);

            $subtotal += $taxableValue;
            $totalTax += $line->gst_amount;

            $items[] = [
                'product_id' => $line->product_id,
                'product_name' => $line->product->name,
                'product_code' => $line->product->product_code,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'taxable_value' => $taxableValue,
                'gst_rate' => $line->gst_rate,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total_tax' => $line->gst_amount,
                'line_total' => $line->line_total,
            ];
        }

        return [
            'subtotal' => $subtotal,
            'total_tax' => $totalTax,
            'grand_total' => $order->total_payable + ($order->coin_redeemed ?? 0) + ($order->coupon_discount ?? 0),
            'items' => $items,
            'tax_breakdown' => json_decode($order->tax_breakdown, true) ?? [],
        ];
    }
}
