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
     * 30 days from date of purchase (NOT delivery)
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

        // 2. Check if order is already cancelled or returned
        if (in_array($order->status, ['cancelled', 'returned'])) {
            return response()->json([
                'success' => false,
                'message' => 'This order is already cancelled or returned.'
            ], 422);
        }

        // 3. Check cooling-off period (from PURCHASE date, not delivery)
        $coolingOffDays = (int) setting('cooling_off_days', 30);
        $daysSincePurchase = $order->created_at->diffInDays(now());

        if ($daysSincePurchase > $coolingOffDays) {
            return response()->json([
                'success' => false,
                'message' => "Cooling-off period has expired. You can only withdraw within {$coolingOffDays} days of purchase. ({$daysSincePurchase} days passed)"
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

        // 6. Prepare return items (all items in the order)
        $returnItems = [];
        $totalRefund = 0;
        $totalCvReversed = 0;
        $processedLineIds = [];

        foreach ($order->lines as $line) {
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
                'message' => 'No items available for return in this order.'
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
                'status' => 'pending',
                'reason' => 'Cooling-off withdrawal (no reason required)',
                'refund_subtotal' => $totalRefund,
                'refund_tax' => 0,
                'refund_shipping' => 0,
                'total_refund_amount' => $totalRefund,
                'total_cv_reversed' => $totalCvReversed,
                'extra_data' => [
                    'cooling_off_days' => $coolingOffDays,
                    'days_since_purchase' => $daysSincePurchase,
                    'withdrawal_initiated_at' => now()->toDateTimeString(),
                ],
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
                    'status' => 'pending',
                    'refund_amount' => $totalRefund,
                    'items_count' => count($returnItems),
                    'remaining_days' => max(0, $coolingOffDays - $daysSincePurchase),
                    'expiry_date' => $order->created_at->addDays($coolingOffDays)->toDateString(),
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
     * Get cooling-off eligibility for an order.
     * GET /api/orders/{orderReference}/cooling-off-eligibility
     */
    public function eligibility(Request $request, string $orderReference): JsonResponse
    {
        $user = Auth::user();
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        $coolingOffDays = (int) setting('cooling_off_days', 30);
        $daysSincePurchase = $order->created_at->diffInDays(now());
        $isEligible = $order->created_at->diffInDays(now()) <= $coolingOffDays
            && !$order->hasPendingReturn()
            && !$order->hasApprovedReturn()
            && !in_array($order->status, ['cancelled', 'returned']);

        return response()->json([
            'success' => true,
            'data' => [
                'order_reference' => $order->order_reference,
                'is_eligible' => $isEligible,
                'cooling_off_days' => $coolingOffDays,
                'days_since_purchase' => $daysSincePurchase,
                'remaining_days' => max(0, $coolingOffDays - $daysSincePurchase),
                'expiry_date' => $order->created_at->addDays($coolingOffDays)->toDateString(),
                'order_status' => $order->status,
                'has_pending_return' => $order->hasPendingReturn(),
                'has_approved_return' => $order->hasApprovedReturn(),
            ]
        ]);
    }

    /**
     * Get history of cooling-off withdrawals for the authenticated user.
     * GET /api/cooling-off/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = Auth::user();
        $returns = OrderReturn::where('user_id', $user->id)
            ->where('type', 'cooling_off')
            ->with('order')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $returns->map(function ($return) {
                return [
                    'id' => $return->id,
                    'order_reference' => $return->order->order_reference ?? null,
                    'status' => $return->status,
                    'items_count' => count($return->items),
                    'total_refund' => (float) $return->total_refund_amount,
                    'reason' => $return->reason,
                    'admin_notes' => $return->admin_notes,
                    'rejection_reason' => $return->rejection_reason,
                    'created_at' => $return->created_at->toDateTimeString(),
                    'approved_at' => $return->approved_at?->toDateTimeString(),
                    'completed_at' => $return->completed_at?->toDateTimeString(),
                    'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
                ];
            }),
            'total' => $returns->count(),
        ]);
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