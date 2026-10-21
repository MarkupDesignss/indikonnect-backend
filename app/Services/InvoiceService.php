<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class InvoiceService
{
    /**
     * Generate invoice(s) for given order lines.
     *
     * - $lines === null  => poore order ki saari lines (har line ka alag invoice)
     * - $lines passed    => sirf in lines ka invoice (partial dispatch)
     *
     * @return Invoice|Collection  single line => single Invoice, multiple => Collection
     */
    public function generateInvoice(Order $order, $lines = null)
    {
        // Normalize input to Collection of OrderLine models
        if ($lines === null) {
            $lines = $order->lines;
        }

        $lines = $lines instanceof Collection
            ? $lines
            : collect(is_array($lines) ? $lines : [$lines]);

        $lines = $lines->filter()->values();

        if ($lines->isEmpty()) {
            throw new \Exception('No order lines provided for invoice generation.');
        }

        // 1 invoice per order line (kyunki invoices.order_line_id hai)
        $invoices = $lines->map(function ($line) use ($order) {
            return $this->generateInvoiceForLine($order, $line);
        });

        // Agar single line thi to single Invoice return karo (backward-friendly)
        return $invoices->count() === 1 ? $invoices->first() : $invoices;
    }

    /**
     * Ek order line ke liye ek invoice banao.
     */
    protected function generateInvoiceForLine(Order $order, $line): Invoice
    {
        $taxData = is_array($line->tax_data)
            ? $line->tax_data
            : (json_decode($line->tax_data ?? '[]', true) ?: []);

        $taxableValue = $taxData['taxable_value'] ?? ($line->unit_price * $line->quantity);
        $cgst         = $taxData['cgst'] ?? 0;
        $sgst         = $taxData['sgst'] ?? 0;
        $igst         = $taxData['igst'] ?? 0;
        $gstAmount    = $line->gst_amount ?? ($cgst + $sgst + $igst);

        $totalTax    = $cgst + $sgst + $igst;
        $totalAmount = $taxableValue + $totalTax;

        // Is line ke liye discount/coin/coupon proportional share
        // (kyunki poora discount ek hi line par daalna galat hoga)
        [$coinShare, $couponShare, $couponCode, $shippingShare, $subtotalBeforeRedemption] =
            $this->allocateOrderLevelCharges($order, $line, $taxableValue);

        $lineItems = [[
            'order_line_id' => $line->id,
            'product_name'  => $line->product->name ?? 'Unknown',
            'product_code'  => $line->product->product_code ?? '',
            'quantity'      => $line->quantity,
            'unit_price'    => $line->unit_price,
            'taxable_value' => $taxableValue,
            'gst_rate'      => $line->gst_rate,
            'cgst'          => $cgst,
            'sgst'          => $sgst,
            'igst'          => $igst,
            'gst_amount'    => $gstAmount,
            'line_total'    => $line->line_total,
        ]];

        $totalPayable = max(
            0,
            $totalAmount + $shippingShare - $coinShare - $couponShare
        );

        return Invoice::create([
            'invoice_number'             => $this->generateInvoiceNumber(),
            'order_id'                   => $order->id,
            'order_line_id'              => $line->id,
            'seller_name'                => config('app.company_name', 'IndieKonnect'),
            'seller_gstin'               => config('app.company_gstin', ''),
            'seller_address'             => config('app.company_address', 'Punjab'),
            'buyer_name'                 => $order->user->full_name,
            'buyer_gstin'                => $order->user->distributorProfile?->gst_number ?? '',
            'buyer_address'              => $order->deliveryAddress->address_line_1,
            'delivery_state'             => $order->deliveryAddress->state,
            'line_items'                 => json_encode($lineItems),
            'subtotal_before_redemption' => $subtotalBeforeRedemption,
            'coin_redeemed'              => $coinShare,
            'total_taxable'              => $taxableValue,
            'total_cgst'                 => $cgst,
            'total_sgst'                 => $sgst,
            'total_igst'                 => $igst,
            'total_tax'                  => $totalTax,
            'coupon_code'                => $couponCode,
            'coupon_discount'            => $couponShare,
            'shipping_charge'            => $shippingShare,
            'subtotal_after_discount'    => max(0, $taxableValue - $couponShare),
            'total'                      => $totalAmount,
            'total_payable'              => round($totalPayable, 2),
            'summary_snapshot'           => json_encode([
                'items'       => $lineItems,
                'partial'     => $order->lines->count() > 1,
                'order_lines' => [$line->id],
            ]),
            'issued_at'                  => now(),
        ]);
    }

    /**
     * Order-level discount/coin/coupon/shipping ko line par proportionally allocate karo.
     * Rule: line_share = (line_taxable / order_total_taxable) * order_level_amount
     *
     * @return array [coinShare, couponShare, couponCode, shippingShare, subtotalBeforeRedemption]
     */
    protected function allocateOrderLevelCharges(Order $order, $line, float $lineTaxable): array
    {
        // Order ke saari lines ka total taxable nikaalo
        $orderTaxableTotal = $order->lines->sum(function ($l) {
            $td = is_array($l->tax_data)
                ? $l->tax_data
                : (json_decode($l->tax_data ?? '[]', true) ?: []);
            return $td['taxable_value'] ?? ($l->unit_price * $l->quantity);
        });

        $ratio = $orderTaxableTotal > 0 ? ($lineTaxable / $orderTaxableTotal) : 0;

        $coinShare     = round(($order->coin_redeemed ?? 0) * $ratio, 2);
        $couponShare   = round(($order->coupon_discount ?? 0) * $ratio, 2);
        $shippingShare = round(($order->shipping_charge ?? 0) * $ratio, 2);

        // Coupon code sirf us line par jiske share mein coupon laga ho
        $couponCode = $couponShare > 0 ? $order->coupon_code : null;

        $subtotalBeforeRedemption = round($lineTaxable + $coinShare + $couponShare, 2);

        return [$coinShare, $couponShare, $couponCode, $shippingShare, $subtotalBeforeRedemption];
    }

    protected function generateInvoiceNumber(): string
    {
        $last = Invoice::orderBy('id', 'desc')->first();
        $next = $last ? ((int) substr($last->invoice_number, 4)) + 1 : 1001;
        return 'INV' . str_pad($next, 6, '0', STR_PAD_LEFT);
    }

    protected function recalculateFromOrderLines(Order $order)
    {
        // ... aapka existing code (waise hi rakh sakte hain)
    }
}
