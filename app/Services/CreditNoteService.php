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
     *
     * @param OrderReturn $returnOrder
     * @param int $refundId
     * @return CreditNote
     * @throws \Exception
     */
    public function generateFromReturn(OrderReturn $returnOrder, int $refundId): CreditNote
    {
        // Load order with delivery address
        if (!$returnOrder->relationLoaded('order')) {
            $returnOrder->load(['order.deliveryAddress', 'order.user']);
        } elseif (!$returnOrder->order->relationLoaded('deliveryAddress')) {
            $returnOrder->order->load('deliveryAddress');
        }

        $order = $returnOrder->order;
        $refund = Refund::find($refundId);

        if (!$order || !$refund) {
            throw new \Exception('Order or Refund not found for credit note generation.');
        }

        // Check for existing credit note (idempotency)
        $existing = CreditNote::where('refund_id', $refundId)->first();
        if ($existing) {
            Log::info('Credit note already exists for refund', [
                'refund_id' => $refundId,
                'credit_note_id' => $existing->id,
            ]);
            return $existing;
        }

        return DB::transaction(function () use ($returnOrder, $order, $refund) {
            // ============================================================
            // GET BUYER STATE FROM DELIVERY ADDRESS (NO FALLBACK)
            // ============================================================
            $buyerState = $order->deliveryAddress?->state;

            // NO DEFAULT FALLBACK – if missing, throw exception
            if (empty($buyerState)) {
                Log::error('Buyer state missing for credit note', [
                    'order_id' => $order->id,
                    'delivery_address_id' => $order->delivery_address_id,
                    'return_id' => $returnOrder->id,
                ]);
                throw new \Exception('Cannot generate credit note: Buyer delivery state is missing.');
            }

            $supplierState = $this->getSupplierState();

            Log::info('Credit note state comparison', [
                'order_id'           => $order->id,
                'buyer_state'        => $buyerState,
                'supplier_state'     => $supplierState,
                'gst_type'           => ($buyerState === $supplierState) ? 'Intra-state (CGST+SGST)' : 'Inter-state (IGST)',
            ]);

            // ============================================================
            // PROCESS RETURN ITEMS
            // ============================================================
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
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $gstRate = (float) ($item['gst_rate'] ?? 0);
                $lineTotal = (float) ($item['line_total'] ?? 0);

                // Per-unit calculations
                $perUnitTotal = $lineTotal / $quantity;
                $perUnitTax = $perUnitTotal - $unitPrice;

                // GST split based on states
                $cgst = 0;
                $sgst = 0;
                $igst = 0;

                if ($buyerState === $supplierState) {
                    // Intra-state: CGST + SGST (50:50)
                    $cgst = round(($perUnitTax / 2) * $quantity, 2);
                    $sgst = round(($perUnitTax / 2) * $quantity, 2);
                } else {
                    // Inter-state: IGST only
                    $igst = round($perUnitTax * $quantity, 2);
                }

                $itemTaxable = $unitPrice * $quantity;

                $formattedItems[] = [
                    'order_line_id'   => $orderLine->id,
                    'product_id'      => $orderLine->product_id,
                    'product_name'    => $orderLine->product?->name ?? 'Unknown Product',
                    'product_code'    => $orderLine->product?->product_code ?? null,
                    'quantity'        => $quantity,
                    'unit_price'      => round($unitPrice, 2),
                    'taxable_value'   => round($itemTaxable, 2),
                    'gst_rate'        => $gstRate,
                    'cgst'            => $cgst,
                    'sgst'            => $sgst,
                    'igst'            => $igst,
                    'line_total'      => round($lineTotal, 2),
                ];

                $taxableValue += $itemTaxable;
                $cgstTotal += $cgst;
                $sgstTotal += $sgst;
                $igstTotal += $igst;
                $totalAmount += $lineTotal;
            }

            $totalGst = $cgstTotal + $sgstTotal + $igstTotal;

            // ============================================================
            // GET BUYER DETAILS FROM DELIVERY ADDRESS
            // ============================================================
            $deliveryAddress = $order->deliveryAddress;
            $buyerName = $order->buyer_name ?? $deliveryAddress?->name ?? $order->user?->name ?? 'Unknown';
            $buyerEmail = $order->buyer_email ?? $order->user?->email ?? null;
            $buyerAddress = $deliveryAddress?->address ?? $order->shipping_address ?? null;
            $buyerGstin = $order->buyer_gstin ?? $deliveryAddress?->gstin ?? null;

            // ============================================================
            // CREATE CREDIT NOTE
            // ============================================================
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

            Log::info('Credit note generated successfully', [
                'credit_note_id'    => $creditNote->id,
                'credit_note_number'=> $creditNote->credit_note_number,
                'refund_id'         => $refund->id,
                'return_id'         => $returnOrder->id,
                'order_id'          => $order->id,
                'buyer_state'       => $buyerState,
                'supplier_state'    => $supplierState,
                'total_amount'      => round($totalAmount, 2),
            ]);

            return $creditNote;
        });
    }
}