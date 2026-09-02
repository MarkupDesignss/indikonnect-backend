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
     * Generate a credit note from a completed return/refund
     *
     * @param OrderReturn $returnOrder
     * @param int $refundId
     * @return CreditNote
     * @throws \Exception
     */
    public function generateFromReturn(OrderReturn $returnOrder, int $refundId): CreditNote
    {
        $order = $returnOrder->order;
        $refund = Refund::find($refundId);

        if (!$order || !$refund) {
            throw new \Exception('Order or Refund not found for credit note generation.');
        }

        // Check if credit note already exists for this refund
        $existing = CreditNote::where('refund_id', $refundId)->first();
        if ($existing) {
            Log::info('Credit note already exists for refund', [
                'refund_id' => $refundId,
                'credit_note_id' => $existing->id,
            ]);
            return $existing;
        }

        return DB::transaction(function () use ($returnOrder, $order, $refund) {
            // Calculate totals from returned items
            $items = $returnOrder->items ?? [];
            $taxableValue = 0;
            $cgstTotal = 0;
            $sgstTotal = 0;
            $igstTotal = 0;
            $totalGst = 0;
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

                // Calculate per-unit values
                $perUnitTotal = $lineTotal / $quantity;
                $perUnitTax = $perUnitTotal - $unitPrice;

                // GST split (assuming 50:50 for CGST/SGST for intra-state)
                // For inter-state, it would be IGST only
                $cgst = 0;
                $sgst = 0;
                $igst = 0;

                $taxPerUnit = $perUnitTax;
                if ($order->delivery_state === $order->supplier_state) {
                    // Intra-state: split into CGST + SGST
                    $cgst = $taxPerUnit / 2 * $quantity;
                    $sgst = $taxPerUnit / 2 * $quantity;
                } else {
                    // Inter-state: IGST only
                    $igst = $taxPerUnit * $quantity;
                }

                $itemTaxable = $unitPrice * $quantity;

                $formattedItems[] = [
                    'order_line_id' => $orderLine->id,
                    'product_id' => $orderLine->product_id,
                    'product_name' => $orderLine->product?->name ?? 'Unknown Product',
                    'product_code' => $orderLine->product?->product_code ?? null,
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'taxable_value' => round($itemTaxable, 2),
                    'gst_rate' => $gstRate,
                    'cgst' => round($cgst, 2),
                    'sgst' => round($sgst, 2),
                    'igst' => round($igst, 2),
                    'line_total' => round($lineTotal, 2),
                ];

                $taxableValue += $itemTaxable;
                $cgstTotal += $cgst;
                $sgstTotal += $sgst;
                $igstTotal += $igst;
                $totalAmount += $lineTotal;
            }

            $totalGst = $cgstTotal + $sgstTotal + $igstTotal;

            // Create credit note
            $creditNote = CreditNote::create([
                'order_id'                => $order->id,
                'original_invoice_number' => $order->invoice_number ?? $order->order_reference,
                'refund_id'               => $refund->id,
                'buyer_name'              => $order->buyer_name ?? $order->user?->name ?? 'Unknown',
                'buyer_email'             => $order->buyer_email ?? $order->user?->email ?? null,
                'buyer_address'           => $order->shipping_address ?? null,
                'buyer_state'             => $order->shipping_state ?? null,
                'buyer_gstin'             => $order->buyer_gstin ?? null,
                'buyer_type'              => $order->order_type ?? 'customer', // 'customer' or 'distributor'
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
                'credit_note_id' => $creditNote->id,
                'credit_note_number' => $creditNote->credit_note_number,
                'refund_id' => $refund->id,
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
            ]);

            return $creditNote;
        });
    }
}