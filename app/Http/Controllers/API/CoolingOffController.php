<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoolingOffController extends Controller
{
    /**
     * Initiate cooling-off withdrawal for an order.
     * POST /api/orders/{orderReference}/cooling-off-withdraw
     * 
     * @param Request $request
     * @param string $orderReference
     * @return JsonResponse
     */
    public function withdraw(Request $request, string $orderReference): JsonResponse
    {
        $user = Auth::user();

        // 1. Find the order
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $user->id)
            ->with(['lines.product'])
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or does not belong to you.'
            ], 404);
        }

        // 2. Check if order is delivered
        if ($order->status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'Cooling-off withdrawal is only available for delivered orders.'
            ], 422);
        }

        // 3. Check cooling-off period (configurable from settings)
        $coolingOffDays = (int) setting('cooling_off_days', 30);

        if (!$order->delivered_at) {
            return response()->json([
                'success' => false,
                'message' => 'Order delivery date not found.'
            ], 422);
        }

        $daysSinceDelivery = $order->delivered_at->diffInDays(now());

        if ($daysSinceDelivery > $coolingOffDays) {
            return response()->json([
                'success' => false,
                'message' => "Cooling-off period has expired. You can only withdraw within {$coolingOffDays} days of delivery. ({$daysSinceDelivery} days passed)"
            ], 422);
        }

        // 4. Check if already returned or cooling-off already initiated
        if ($order->hasPendingReturn() || $order->hasApprovedReturn()) {
            return response()->json([
                'success' => false,
                'message' => 'A return or withdrawal request is already in progress for this order.'
            ], 422);
        }

        // 5. Check if any items are already returned
        $hasReturnedItems = $order->lines->contains(function ($line) {
            return in_array($line->return_status, ['returned', 'approved', 'pending']);
        });

        if ($hasReturnedItems) {
            return response()->json([
                'success' => false,
                'message' => 'Some items in this order have already been returned. Cooling-off withdrawal requires the entire order.'
            ], 422);
        }

        // 6. Prepare return items (all delivered items)
        $returnItems = [];
        $totalRefund = 0;
        $totalCvReversed = 0;
        $processedLineIds = [];

        foreach ($order->lines as $line) {
            // Only delivered items can be returned
            if ($line->delivery_status !== 'delivered') {
                continue;
            }

            $availableQty = $line->quantity - ($line->returned_quantity ?? 0);
            if ($availableQty <= 0) {
                continue;
            }

            $perUnitLineTotal = (float) $line->line_total / $line->quantity;
            $itemTotal = $perUnitLineTotal * $availableQty;

            $cvPerUnit = (float) ($line->commissionable_volume ?? 0) / $line->quantity;
            $cvReversed = $cvPerUnit * $availableQty;

            $returnItems[] = [
                'order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product->name ?? 'Unknown',
                'quantity' => $availableQty,
                'unit_price' => (float) $line->unit_price,
                'gst_rate' => (float) $line->gst_rate,
                'subtotal' => $itemTotal,
                'tax' => 0,
                'line_total' => $itemTotal,
                'reason' => 'Cooling-off withdrawal (no reason required)',
                'image_paths' => [],
                'return_status' => 'pending',
            ];

            $totalRefund += $itemTotal;
            $totalCvReversed += $cvReversed;
            $processedLineIds[] = $line->id;
        }

        if (empty($returnItems)) {
            return response()->json([
                'success' => false,
                'message' => 'No deliverable items found in this order.'
            ], 422);
        }

        // 7. Create return record with type 'cooling_off'
        DB::beginTransaction();

        try {
            $returnOrder = OrderReturn::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => 'cooling_off',
                'items' => $returnItems,
                'status' => OrderReturn::STATUS_PENDING,
                'reason' => 'Cooling-off withdrawal (no reason required)',
                'refund_subtotal' => $totalRefund,
                'refund_tax' => 0,
                'refund_shipping' => 0,
                'total_refund_amount' => $totalRefund,
                'total_cv_reversed' => $totalCvReversed,
            ]);

            // Update order lines
            foreach ($returnItems as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine) {
                    $orderLine->update([
                        'return_status' => 'pending',
                        'delivery_status' => 'return_pending',
                        'return_requested_at' => now(),
                        'returned_quantity' => ($orderLine->returned_quantity ?? 0) + $item['quantity'],
                    ]);
                }
            }

            // Update order return status
            $order->update([
                'return_status' => 'pending',
            ]);

            // Admin notification
            $this->createCoolingOffNotification($returnOrder);

            DB::commit();

            Log::info('Cooling-off withdrawal initiated', [
                'return_id' => $returnOrder->id,
                'order_reference' => $order->order_reference,
                'user_id' => $user->id,
                'total_refund' => $totalRefund,
                'items_count' => count($returnItems),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cooling-off withdrawal initiated successfully. Admin will review and process your refund within 5-7 business days.',
                'data' => [
                    'return_id' => $returnOrder->id,
                    'order_reference' => $order->order_reference,
                    'status' => OrderReturn::STATUS_PENDING,
                    'refund_amount' => $totalRefund,
                    'items_count' => count($returnItems),
                    'created_at' => $returnOrder->created_at->toDateTimeString(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Cooling-off withdrawal failed', [
                'order_reference' => $order->order_reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate cooling-off withdrawal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create admin notification for cooling-off withdrawal.
     */
    protected function createCoolingOffNotification(OrderReturn $returnOrder): void
    {
        AdminNotification::create([
            'admin_id' => 1,
            'type' => 'cooling_off_pending',
            'title' => 'New Cooling-Off Withdrawal Request',
            'message' => "Cooling-off withdrawal for Order #{$returnOrder->order->order_reference} has been initiated. Total refund: ₹{$returnOrder->total_refund_amount}",
            'reference_type' => 'return',
            'reference_id' => $returnOrder->id,
            'priority' => 'high',
            'extra_data' => json_encode([
                'order_reference' => $returnOrder->order->order_reference ?? null,
                'user_id' => $returnOrder->user_id,
                'total_refund' => (float) $returnOrder->total_refund_amount,
                'items_count' => count($returnOrder->items),
                'status' => $returnOrder->status,
                'return_id' => $returnOrder->id,
                'type' => 'cooling_off',
            ]),
        ]);
    }
}