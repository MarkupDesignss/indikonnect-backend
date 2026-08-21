<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Return as OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class BuybackController extends Controller
{
    /**
     * List eligible stock for buy-back.
     * Rules:
     * - Order must be delivered (status = 'delivered' and delivered_at within window)
     * - Item delivery_status = 'delivered'
     * - Item not already returned (return_status = 'none' or 'rejected')
     * - Available quantity > 0
     */
    public function eligibleStock(Request $request)
    {
        $user = Auth::user();

        // Only active distributors can request buy-back
        if ($user->account_type !== 'distributor' || $user->distributor_status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Only active distributors can request buy-back.'
            ], 403);
        }

        $buybackWindow = (int) setting('buyback_window_days', 30);
        $deductionPercent = (float) setting('buyback_deduction_percent', 0);

        // Get all delivered orders within the window
        $orders = Order::where('user_id', $user->id)
            ->where('status', 'delivered')
            ->where('delivered_at', '>=', now()->subDays($buybackWindow))
            ->with(['lines.product'])
            ->orderBy('delivered_at', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => "No eligible stock found. Only purchases delivered within the last {$buybackWindow} days are eligible.",
                'meta' => [
                    'buyback_window_days' => $buybackWindow,
                    'deduction_percent' => $deductionPercent,
                ]
            ]);
        }

        $eligibleItems = [];
        $totalEligibleValue = 0;

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                // Only delivered items are eligible
                if ($line->delivery_status !== 'delivered') {
                    continue;
                }

                // Check item-level delivered_at within window
                if ($line->delivered_at && $line->delivered_at->diffInDays(now()) > $buybackWindow) {
                    continue;
                }

                // Skip if already returned, pending, or approved
                if (in_array($line->return_status, ['pending', 'approved', 'returned'])) {
                    continue;
                }

                $purchasedQty = (int) $line->quantity;
                $returnedQty = (int) ($line->returned_quantity ?? 0);
                $availableQty = $purchasedQty - $returnedQty;

                if ($availableQty <= 0) {
                    continue;
                }

                // Calculate per-unit line total
                $perUnitTotal = (float) $line->line_total / $purchasedQty;
                $itemTotal = $perUnitTotal * $availableQty;
                $deductionAmount = round($itemTotal * ($deductionPercent / 100), 2);
                $estimatedRefund = round($itemTotal - $deductionAmount, 2);

                $eligibleItems[] = [
                    // Order info
                    'order_reference' => $order->order_reference,
                    'order_date' => $order->created_at->toDateString(),
                    'delivered_at' => $line->delivered_at?->toDateString(),
                    'days_since_delivery' => $line->delivered_at ? $line->delivered_at->diffInDays(now()) : 0,

                    // Product info
                    'order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product->name ?? 'Unknown Product',
                    'product_code' => $line->product->product_code ?? '',

                    // Quantity & pricing
                    'quantity' => $purchasedQty,
                    'already_returned' => $returnedQty,
                    'available_quantity' => $availableQty,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => (float) $line->line_total,
                    'gst_rate' => (float) $line->gst_rate,
                    'gst_amount' => (float) $line->gst_amount,
                    'commissionable_volume' => (float) ($line->commissionable_volume ?? 0),

                    // Refund calculation
                    'deduction_percent' => $deductionPercent,
                    'deduction_amount' => $deductionAmount,
                    'estimated_refund' => $estimatedRefund,

                    'return_reasons' => $this->getReturnReasons(),
                ];

                $totalEligibleValue += $estimatedRefund;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $eligibleItems,
            'meta' => [
                'buyback_window_days' => $buybackWindow,
                'deduction_percent' => $deductionPercent,
                'total_eligible_items' => count($eligibleItems),
                'total_estimated_refund' => round($totalEligibleValue, 2),
            ]
        ]);
    }

    /**
     * Initiate buy-back request.
     */
    public function initiate(Request $request)
    {
        $user = Auth::user();

        if ($user->account_type !== 'distributor' || $user->distributor_status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Only active distributors can request buy-back.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.order_line_id' => 'required|integer|exists:order_lines,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:500',
            'return_reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $buybackWindow = (int) setting('buyback_window_days', 30);
        $deductionPercent = (float) setting('buyback_deduction_percent', 0);

        $returnItems = [];
        $totalRefund = 0;
        $totalDeduction = 0;
        $totalCvReversed = 0;
        $processedLineIds = [];
        $orderId = null;

        DB::beginTransaction();

        try {
            foreach ($data['items'] as $itemData) {
                $orderLine = OrderLine::with(['order', 'product'])->find($itemData['order_line_id']);

                if (!$orderLine) {
                    throw new \Exception("Order line not found: {$itemData['order_line_id']}");
                }

                if ($orderLine->order->user_id !== $user->id) {
                    throw new \Exception("Item '{$orderLine->product->name}' does not belong to you.");
                }

                if (!$orderId) {
                    $orderId = $orderLine->order_id;
                } elseif ($orderId !== $orderLine->order_id) {
                    throw new \Exception("All items must be from the same order.");
                }

                if ($orderLine->delivery_status !== 'delivered') {
                    throw new \Exception("Item '{$orderLine->product->name}' has not been delivered.");
                }

                if ($orderLine->delivered_at && $orderLine->delivered_at->diffInDays(now()) > $buybackWindow) {
                    throw new \Exception("Item '{$orderLine->product->name}' is outside the buy-back window ({$buybackWindow} days).");
                }

                if (in_array($orderLine->return_status, ['pending', 'approved', 'returned'])) {
                    throw new \Exception("Item '{$orderLine->product->name}' already has a return in progress.");
                }

                $purchasedQty = (int) $orderLine->quantity;
                $returnedQty = (int) ($orderLine->returned_quantity ?? 0);
                $availableQty = $purchasedQty - $returnedQty;

                if ($itemData['quantity'] > $availableQty) {
                    throw new \Exception("Only {$availableQty} units of '{$orderLine->product->name}' are available.");
                }

                // Calculate refund
                $perUnitLineTotal = (float) $orderLine->line_total / $purchasedQty;
                $itemTotal = $perUnitLineTotal * $itemData['quantity'];
                $deductionAmount = round($itemTotal * ($deductionPercent / 100), 2);
                $refundAmount = round($itemTotal - $deductionAmount, 2);

                $perUnitCv = (float) ($orderLine->commissionable_volume ?? 0) / $purchasedQty;
                $cvReversed = round($perUnitCv * $itemData['quantity'], 2);

                $returnItems[] = [
                    'order_line_id' => $orderLine->id,
                    'product_id' => $orderLine->product_id,
                    'product_name' => $orderLine->product->name ?? 'Unknown',
                    'quantity' => (int) $itemData['quantity'],
                    'unit_price' => (float) $orderLine->unit_price,
                    'gst_rate' => (float) $orderLine->gst_rate,
                    'subtotal' => $itemTotal, // line total excluding tax if needed
                    'tax' => 0,
                    'line_total' => $itemTotal,
                    'reason' => $itemData['reason'] ?? 'Buy-back request',
                    'image_paths' => [],
                    'return_status' => 'pending',
                    'commissionable_volume_reversed' => $cvReversed,
                ];

                $totalRefund += $refundAmount;
                $totalDeduction += $deductionAmount;
                $totalCvReversed += $cvReversed;
                $processedLineIds[] = $orderLine->id;

                // Update order line status
                $orderLine->update([
                    'returned_quantity' => $returnedQty + $itemData['quantity'],
                    'return_status' => 'pending',
                    'return_requested_at' => now(),
                    'delivery_status' => 'return_initiated',
                ]);
            }

            // Round totals
            $totalRefund = round($totalRefund, 2);
            $totalDeduction = round($totalDeduction, 2);
            $totalCvReversed = round($totalCvReversed, 2);

            // Proportional shipping refund
            $order = Order::find($orderId);
            $refundShipping = 0;
            if ($order && $order->shipping_charge > 0 && $order->subtotal > 0) {
                $refundSubtotal = $totalRefund; // we treat totalRefund as the subtotal of returned items
                $returnedProportion = min($refundSubtotal / $order->subtotal, 1);
                $refundShipping = round($order->shipping_charge * $returnedProportion, 2);
            }

            // Create return record with type 'buyback'
            $return = OrderReturn::create([
                'order_id' => $orderId,
                'user_id' => $user->id,
                'type' => 'buyback',
                'items' => $returnItems,
                'status' => 'pending',
                'reason' => $data['return_reason'] ?? 'Buy-back request',
                'refund_subtotal' => $totalRefund,
                'refund_tax' => 0,
                'refund_shipping' => $refundShipping,
                'total_refund_amount' => $totalRefund + $refundShipping,
                'total_cv_reversed' => $totalCvReversed,
            ]);

            // Optionally, update order return status
            $this->updateOrderReturnStatus($order);

            DB::commit();

            // Admin notification
            $this->createBuybackNotification($return);

            return response()->json([
                'success' => true,
                'message' => 'Buy-back request submitted successfully. Admin will review and notify you.',
                'data' => [
                    'return_id' => $return->id,
                    'status' => 'pending',
                    'order_reference' => $order->order_reference ?? null,
                    'refund_details' => [
                        'subtotal' => $totalRefund,
                        'tax' => 0,
                        'shipping' => $refundShipping,
                        'total' => $totalRefund + $refundShipping,
                        'deduction_amount' => $totalDeduction,
                        'deduction_percent' => $deductionPercent,
                        'cv_reversed' => $totalCvReversed,
                    ],
                    'items' => $returnItems,
                    'created_at' => $return->created_at->toDateTimeString(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Buy-back request failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * History of buy-back requests.
     */
    public function history(Request $request)
    {
        $user = Auth::user();

        $returns = OrderReturn::where('user_id', $user->id)
            ->where('type', 'buyback')
            ->with(['order'])
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
                    'deduction_amount' => (float) $return->refund_subtotal * (setting('buyback_deduction_percent', 0) / 100), // approximate
                    'reason' => $return->reason,
                    'admin_notes' => $return->admin_notes,
                    'rejection_reason' => $return->rejection_reason,
                    'created_at' => $return->created_at->toDateTimeString(),
                    'approved_at' => $return->approved_at?->toDateTimeString(),
                    'completed_at' => $return->completed_at?->toDateTimeString(),
                ];
            }),
            'total' => $returns->count(),
        ]);
    }

    /**
     * Summary of buy-back requests.
     */
    public function summary()
    {
        $user = Auth::user();

        $returns = OrderReturn::where('user_id', $user->id)
            ->where('type', 'buyback')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_requests' => $returns->count(),
                'pending' => $returns->where('status', 'pending')->count(),
                'approved' => $returns->where('status', 'approved')->count(),
                'rejected' => $returns->where('status', 'rejected')->count(),
                'completed' => $returns->where('status', 'completed')->count(),
                'total_refund_amount' => (float) $returns->whereIn('status', ['approved', 'completed'])->sum('total_refund_amount'),
                'total_deduction_amount' => 0, // can calculate later
            ],
        ]);
    }

    // ==================== HELPERS ====================

    private function getReturnReasons(): array
    {
        return [
            'unsold' => 'Product is unsold',
            'unused' => 'Product is unused',
            'defective' => 'Product is defective',
            'expired' => 'Product is near expiry',
            'changed_mind' => 'Changed business strategy',
            'other' => 'Other reason',
        ];
    }

    private function updateOrderReturnStatus($order): void
    {
        $lines = $order->lines;
        $deliveredLines = $lines->where('delivery_status', 'delivered');
        $deliveredCount = $deliveredLines->count();

        if ($deliveredCount === 0) {
            $order->update(['return_status' => 'none']);
            return;
        }

        $statusCounts = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'returned' => 0,
            'none' => 0,
        ];

        foreach ($deliveredLines as $line) {
            $status = $line->return_status ?? 'none';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        $returnStatus = 'none';
        if ($statusCounts['returned'] === $deliveredCount) {
            $returnStatus = 'fully_returned';
        } elseif ($statusCounts['returned'] > 0) {
            $returnStatus = 'partial_return';
        } elseif ($statusCounts['pending'] > 0) {
            $returnStatus = 'pending';
        } elseif ($statusCounts['approved'] > 0) {
            $returnStatus = 'approved';
        } elseif ($statusCounts['rejected'] > 0) {
            $returnStatus = 'rejected';
        }

        $order->update(['return_status' => $returnStatus]);
    }

    private function createBuybackNotification($return): void
    {
        \App\Models\AdminNotification::create([
            'admin_id' => 1,
            'type' => 'buyback_pending',
            'title' => 'New Buy-Back Request',
            'message' => "Buy-back request for Order #{$return->order->order_reference} has been submitted. Total refund: ₹{$return->total_refund_amount}",
            'reference_type' => 'return',
            'reference_id' => $return->id,
            'priority' => 'high',
            'extra_data' => json_encode([
                'order_reference' => $return->order->order_reference ?? null,
                'user_id' => $return->user_id,
                'total_refund' => (float) $return->total_refund_amount,
                'items_count' => count($return->items),
                'status' => $return->status,
                'return_id' => $return->id,
                'type' => 'buyback',
            ]),
        ]);
    }
}