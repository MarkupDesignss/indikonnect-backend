<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /** One invoice per order line. Return existing invoice on repeat calls. */
    public function generateInvoice(Order $order, $lines = null)
    {
        $requested = $lines === null ? null : ($lines instanceof Collection ? $lines : collect(is_array($lines) ? $lines : [$lines]));
        return DB::transaction(function () use ($order, $requested) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $query = $order->lines()->orderBy('id')->lockForUpdate();
            if ($requested !== null) {
                $ids = $requested->filter()->pluck('id')->filter()->unique()->values();
                if ($ids->isEmpty()) throw new \Exception('No order lines provided for invoice generation.');
                $query->whereIn('id', $ids);
            }
            $lines = $query->get();
            if ($lines->isEmpty() || ($requested !== null && $lines->count() !== $ids->count())) {
                throw new \Exception('No valid order lines provided for invoice generation.');
            }
            $invoices = $lines->map(function ($line) use ($order) {
                $existing = Invoice::where('order_line_id', $line->id)->lockForUpdate()->first();
                if ($existing) {
                    if ((int) $existing->order_id !== (int) $order->id) throw new \Exception('Invoice order mismatch.');
                    return $existing;
                }
                return $this->generateInvoiceForLine($order, $line);
            });
            return $invoices->count() === 1 ? $invoices->first() : $invoices;
        }, 3);
    }

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
        $totalPayable = max(0, $totalAmount + $shippingShare - $coinShare - $couponShare);
        return Invoice::create([
            'invoice_number'             => $this->generateInvoiceNumber(),
            'order_id'                   => $order->id,
            'order_line_id'              => $line->id,
            'seller_name'                => config('app.company_name', 'IndieKonnect'),
            'seller_gstin'               => config('app.company_gstin', ''),
            'seller_address'             => config('app.company_address', 'Punjab'),
            'seller_city'                => config('app.seller_city', 'Ludhiyana'),
            'seller_pincode'             => config('app.seller_pincode', '141001'),
            'buyer_name'                 => $order->user->full_name,
            'buyer_gstin'                => $order->user->distributorProfile?->gst_number ?? '',
            'buyer_address'              => $order->deliveryAddress->address_line_1,
            'buyer_city'                 => $order->deliveryAddress->city,
            'buyer_pincode'              => $order->deliveryAddress->postcode,
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

    protected function allocateOrderLevelCharges(Order $order, $line, float $lineTaxable): array
    {
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
        $couponCode = $couponShare > 0 ? $order->coupon_code : null;
        $subtotalBeforeRedemption = round($lineTaxable + $coinShare + $couponShare, 2);
        return [$coinShare, $couponShare, $couponCode, $shippingShare, $subtotalBeforeRedemption];
    }

    protected function generateInvoiceNumber(): string
    {
        // Lock the latest row and require a unique invoice_number DB constraint.
        $last = Invoice::orderBy('id', 'desc')->lockForUpdate()->first();
        if ($last && !preg_match('/^INV[0-9]+$/D', (string) $last->invoice_number)) {
            throw new \Exception('Unexpected invoice number sequence; reconcile before generating.');
        }
        $next = $last ? ((int) substr($last->invoice_number, 3)) + 1 : 1001;
        return 'INV' . str_pad($next, 6, '0', STR_PAD_LEFT);
    }

    protected function recalculateFromOrderLines(Order $order)
    {
        // ... aapka existing code (waise hi rakh sakte hain)
    }
}
