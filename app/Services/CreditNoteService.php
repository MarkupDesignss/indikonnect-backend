<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditNoteService
{
    /**
     * Get supplier state from .env
     */
    protected function getSupplierState(): string
    {
        return env('SUPPLIER_STATE', 'Punjab');
    }

    /**
     * Generate a credit note from a completed return/refund
     */
    public function generateFromReturn(OrderReturn $returnOrder, int $refundId): CreditNote
    {
        // Load order with delivery address & lines
        if (!$returnOrder->relationLoaded('order')) {
            $returnOrder->load(['order.deliveryAddress', 'order.user', 'order.lines']);
        }

        $order = $returnOrder->order;
        $refund = Refund::find($refundId);

        if (!$order || !$refund) {
            throw new \Exception('Order or Refund not found.');
        }

        // Idempotency check
        $existing = CreditNote::where('refund_id', $refundId)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($returnOrder, $order, $refund) {
            $buyerState = $order->deliveryAddress?->state;
            if (empty($buyerState)) {
                throw new \Exception('Buyer delivery state is missing.');
            }

            $supplierState = $this->getSupplierState();

            $items = $returnOrder->items ?? [];
            $taxableValue = 0;
            $cgstTotal = 0;
            $sgstTotal = 0;
            $igstTotal = 0;
            $totalAmount = 0;
            $formattedItems = [];

            foreach ($items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if (!$orderLine) {
                    continue;
                }

                $quantity = (int) ($item['quantity'] ?? 0);
                $unitPrice = (float) ($orderLine->unit_price ?? 0);
                $gstRate = (float) ($orderLine->gst_rate ?? 0);
                $itemTaxable = $unitPrice * $quantity;

                // ============================================================
                //  USE GST FROM ORDER LINE – NOT FROM RETURN ITEMS
                // ============================================================
                // Get GST amounts from the order line (already correct)
                $cgstFromLine = (float) ($orderLine->cgst_amount ?? 0);
                $sgstFromLine = (float) ($orderLine->sgst_amount ?? 0);
                $igstFromLine = (float) ($orderLine->igst_amount ?? 0);

                // Calculate per-unit GST
                $lineQty = $orderLine->quantity ?? 1;
                $cgstPerUnit = $cgstFromLine / $lineQty;
                $sgstPerUnit = $sgstFromLine / $lineQty;
                $igstPerUnit = $igstFromLine / $lineQty;

                // If any GST exists on the order line, use it
                if ($cgstFromLine > 0 || $sgstFromLine > 0 || $igstFromLine > 0) {
                    $cgst = round($cgstPerUnit * $quantity, 2);
                    $sgst = round($sgstPerUnit * $quantity, 2);
                    $igst = round($igstPerUnit * $quantity, 2);
                } else {
                    // Fallback: calculate from GST rate
                    $totalGstAmount = round($itemTaxable * ($gstRate / 100), 2);
                    if ($buyerState === $supplierState) {
                        $cgst = round($totalGstAmount / 2, 2);
                        $sgst = round($totalGstAmount / 2, 2);
                        $igst = 0;
                    } else {
                        $cgst = 0;
                        $sgst = 0;
                        $igst = round($totalGstAmount, 2);
                    }
                }

                // LINE TOTAL = TAXABLE + GST (always positive)
                $lineTotal = round($itemTaxable + $cgst + $sgst + $igst, 2);

                $formattedItems[] = [
                    'order_line_id'   => $orderLine->id,
                    'product_id'      => $orderLine->product_id,
                    'product_name'    => $orderLine->product?->name ?? 'Unknown',
                    'product_code'    => $orderLine->product?->product_code ?? null,
                    'quantity'        => $quantity,
                    'unit_price'      => round($unitPrice, 2),
                    'taxable_value'   => round($itemTaxable, 2),
                    'gst_rate'        => $gstRate,
                    'cgst'            => $cgst,
                    'sgst'            => $sgst,
                    'igst'            => $igst,
                    'line_total'      => $lineTotal,
                ];

                $taxableValue += $itemTaxable;
                $cgstTotal += $cgst;
                $sgstTotal += $sgst;
                $igstTotal += $igst;
                $totalAmount += $lineTotal;
            }

            $totalGst = $cgstTotal + $sgstTotal + $igstTotal;

            // Buyer details
            $deliveryAddress = $order->deliveryAddress;
            $buyerName = $order->buyer_name ?? $deliveryAddress?->name ?? $order->user?->name ?? 'Unknown';
            $buyerEmail = $order->buyer_email ?? $order->user?->email ?? null;
            $buyerAddress = $deliveryAddress?->address ?? $order->shipping_address ?? null;
            $buyerGstin = $order->buyer_gstin ?? $deliveryAddress?->gstin ?? null;

            // Create credit note
            $creditNote = CreditNote::create([
                'order_id'                => $order->id,
                'original_invoice_number' => $order->invoice_number ?? $order->order_reference,
                'refund_id'               => $refund->id,
                'buyer_name'              => $buyerName,
                'buyer_email'             => $buyerEmail,
                'buyer_address'           => $buyerAddress,
                'buyer_state'             => $buyerState,
                'buyer_gstin'             => $buyerGstin,
                'buyer_type'              => $order->order_type ?? 'customer',
                'items'                   => $formattedItems,
                'taxable_value'           => round($taxableValue, 2),
                'cgst_amount'             => round($cgstTotal, 2),
                'sgst_amount'             => round($sgstTotal, 2),
                'igst_amount'             => round($igstTotal, 2),
                'total_gst'               => round($totalGst, 2),
                'amount'                  => round($totalAmount, 2),
                'reason'                  => $returnOrder->reason ?? $returnOrder->type ?? 'Return/Refund',
                'issued_at'               => now(),
            ]);

            Log::info('Credit note generated', [
                'credit_note_id' => $creditNote->id,
                'buyer_state'    => $buyerState,
                'supplier_state' => $supplierState,
                'cgst'           => $cgstTotal,
                'sgst'           => $sgstTotal,
                'igst'           => $igstTotal,
                'total_gst'      => $totalGst,
            ]);

            return $creditNote;
        });
    }
}