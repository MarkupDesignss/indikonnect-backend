<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class BuybackController extends Controller
{
    /**
     * List eligible stock for buy-back.
     * 30 days from date of PURCHASE (not delivery).
     * Orders must be confirmed, processing, shipped, or delivered.
     * 
     * GET /distributor/buyback/eligible
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

        // Get all eligible orders: within window, not cancelled/returned
        $orders = Order::where('user_id', $user->id)
            ->whereNotIn('status', ['cancelled', 'returned', 'fully_returned'])
            ->where('created_at', '>=', now()->subDays($buybackWindow))
            ->with(['lines.product'])
            ->orderBy('created_at', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => "No eligible stock found. Only purchases made within the last {$buybackWindow} days are eligible.",
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
                // ✅ FIX: Check if line is eligible for buyback
                if (!$this->isLineEligibleForBuyback($line, $order, $buybackWindow)) {
                    continue;
                }

                $purchasedQty = (int) $line->quantity;
                $returnedQty = (int) ($line->returned_quantity ?? 0);
                $availableQty = $purchasedQty - $returnedQty;

                // ✅ FIX: Skip if no available quantity
                if ($availableQty <= 0) {
                    continue;
                }

                // Calculate per-unit line total
                $perUnitTotal = (float) $line->line_total / $purchasedQty;
                $itemTotal = $perUnitTotal * $availableQty;
                $deductionAmount = round($itemTotal * ($deductionPercent / 100), 2);
                $estimatedRefund = round($itemTotal - $deductionAmount, 2);

                // ✅ FIX: Safe product name with fallback
                $productName = $line->product ? $line->product->name : 'Unknown Product (ID: ' . $line->product_id . ')';
                $productCode = $line->product ? $line->product->product_code : '';

                $eligibleItems[] = [
                    // Order info
                    'order_reference' => $order->order_reference,
                    'order_date' => $order->created_at->toDateString(),
                    'purchase_date' => $order->created_at->toDateString(),
                    'days_since_purchase' => $order->created_at->diffInDays(now()),

                    // Product info
                    'order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $productName,
                    'product_code' => $productCode,

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
     * Requires distributor declaration for unsold, unused, marketable.
     * 
     * POST /distributor/buyback/initiate
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
            'declares_marketable' => 'required|boolean|accepted',
            'declares_unsold' => 'required|boolean|accepted',
            'declares_unused' => 'required|boolean|accepted',
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
        $totalTax = 0;
        $processedLineIds = [];
        $orderId = null;

        DB::beginTransaction();

        try {
            foreach ($data['items'] as $itemData) {
                $orderLine = OrderLine::with(['order', 'product'])->find($itemData['order_line_id']);

                if (!$orderLine) {
                    throw new \Exception("Order line not found: {$itemData['order_line_id']}");
                }

                // ✅ FIX: Safe product name with fallback
                $productName = $orderLine->product ? $orderLine->product->name : 'Unknown Product (ID: ' . $orderLine->product_id . ')';

                // ✅ FIX: Detailed validation with proper error messages
                $this->validateOrderLineForBuyback($orderLine, $user, $productName, $buybackWindow, $itemData['quantity']);

                if (!$orderId) {
                    $orderId = $orderLine->order_id;
                } elseif ($orderId !== $orderLine->order_id) {
                    throw new \Exception("All items must be from the same order.");
                }

                $purchasedQty = (int) $orderLine->quantity;
                $returnedQty = (int) ($orderLine->returned_quantity ?? 0);
                $availableQty = $purchasedQty - $returnedQty;

                // ✅ FIX: Double-check available quantity
                if ($availableQty <= 0) {
                    throw new \Exception("No available quantity for '{$productName}'. Purchased: {$purchasedQty}, Already returned: {$returnedQty}");
                }

                if ($itemData['quantity'] > $availableQty) {
                    throw new \Exception("Only {$availableQty} units of '{$productName}' are available. You requested {$itemData['quantity']}.");
                }

                // Calculate refund with GST
                $perUnitLineTotal = (float) $orderLine->line_total / $purchasedQty;
                $perUnitSubtotal = (float) $orderLine->unit_price;
                $perUnitTax = $perUnitLineTotal - $perUnitSubtotal;

                $itemTotal = $perUnitLineTotal * $itemData['quantity'];
                $itemSubtotal = $perUnitSubtotal * $itemData['quantity'];
                $itemTax = $perUnitTax * $itemData['quantity'];

                $deductionAmount = round($itemTotal * ($deductionPercent / 100), 2);
                $refundAmount = round($itemTotal - $deductionAmount, 2);

                $perUnitCv = (float) ($orderLine->commissionable_volume ?? 0) / $purchasedQty;
                $cvReversed = round($perUnitCv * $itemData['quantity'], 2);

                $returnItems[] = [
                    'order_line_id' => $orderLine->id,
                    'product_id' => $orderLine->product_id,
                    'product_name' => $productName,
                    'quantity' => (int) $itemData['quantity'],
                    'unit_price' => (float) $orderLine->unit_price,
                    'gst_rate' => (float) $orderLine->gst_rate,
                    'subtotal' => round($itemSubtotal, 2),
                    'tax' => round($itemTax, 2),
                    'line_total' => round($itemTotal, 2),
                    'reason' => $itemData['reason'] ?? 'Buy-back request',
                    'image_paths' => [],
                    'return_status' => 'pending',
                    'commissionable_volume_reversed' => $cvReversed,
                ];

                $totalRefund += $refundAmount;
                $totalDeduction += $deductionAmount;
                $totalCvReversed += $cvReversed;
                $totalTax += $itemTax;
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
            $totalTax = round($totalTax, 2);

            // Proportional shipping refund
            $order = Order::find($orderId);
            $refundShipping = 0;
            if ($order && $order->shipping_charge > 0 && $order->subtotal > 0) {
                $refundSubtotal = $totalRefund;
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
                'refund_tax' => $totalTax,
                'refund_shipping' => $refundShipping,
                'total_refund_amount' => $totalRefund + $refundShipping,
                'total_cv_reversed' => $totalCvReversed,
                'extra_data' => [
                    'declares_marketable' => (bool) $data['declares_marketable'],
                    'declares_unsold' => (bool) $data['declares_unsold'],
                    'declares_unused' => (bool) $data['declares_unused'],
                    'declared_at' => now()->toDateTimeString(),
                    'deduction_percent' => $deductionPercent,
                    'deduction_amount' => $totalDeduction,
                    'buyback_window_days' => $buybackWindow,
                    'days_since_purchase' => $order->created_at->diffInDays(now()) ?? 0,
                ],
            ]);

            // Update order return status
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
                        'tax' => $totalTax,
                        'shipping' => $refundShipping,
                        'total' => $totalRefund + $refundShipping,
                        'deduction_amount' => $totalDeduction,
                        'deduction_percent' => $deductionPercent,
                        'cv_reversed' => $totalCvReversed,
                    ],
                    'declarations' => [
                        'marketable' => (bool) $data['declares_marketable'],
                        'unsold' => (bool) $data['declares_unsold'],
                        'unused' => (bool) $data['declares_unused'],
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
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * History of buy-back requests.
     * GET /distributor/buyback/history
     */
    public function history(Request $request)
    {
        $user = Auth::user();

        $query = OrderReturn::where('user_id', $user->id)
            ->where('type', 'buyback')
            ->with(['order']);

        // Status filter
        if ($request->has('status') && in_array($request->status, ['pending', 'approved', 'rejected', 'received', 'completed'])) {
            $query->where('status', $request->status);
        }

        // Pagination
        $perPage = $request->input('per_page', 20);
        $returns = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $returns->map(function ($return) {
                return [
                    'id' => $return->id,
                    'order_reference' => $return->order->order_reference ?? null,
                    'status' => $return->status,
                    'items_count' => count($return->items),
                    'total_refund' => (float) $return->total_refund_amount,
                    'deduction_amount' => (float) ($return->extra_data['deduction_amount'] ?? 0),
                    'deduction_percent' => (float) ($return->extra_data['deduction_percent'] ?? 0),
                    'reason' => $return->reason,
                    'admin_notes' => $return->admin_notes,
                    'rejection_reason' => $return->rejection_reason,
                    'created_at' => $return->created_at->toDateTimeString(),
                    'approved_at' => $return->approved_at?->toDateTimeString(),
                    'received_at' => $return->received_at?->toDateTimeString(),
                    'completed_at' => $return->completed_at?->toDateTimeString(),
                ];
            }),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
                'last_page' => $returns->lastPage(),
            ],
        ]);
    }

    /**
     * Summary of buy-back requests.
     * GET /distributor/buyback/summary
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
                'received' => $returns->where('status', 'received')->count(),
                'completed' => $returns->where('status', 'completed')->count(),
                'total_refund_amount' => (float) $returns->whereIn('status', ['approved', 'received', 'completed'])->sum('total_refund_amount'),
                'total_deduction_amount' => (float) $returns->sum(function($return) {
                    return $return->extra_data['deduction_amount'] ?? 0;
                }),
                'total_cv_reversed' => (float) $returns->whereIn('status', ['approved', 'received', 'completed'])->sum('total_cv_reversed'),
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

    /**
     * Check if an order line is eligible for buyback
     */
    private function isLineEligibleForBuyback($line, $order, int $buybackWindow): bool
    {
        // Only delivered items are physically available
        if ($line->delivery_status !== 'delivered') {
            return false;
        }

        // Check purchase date within window
        if ($order->created_at->diffInDays(now()) > $buybackWindow) {
            return false;
        }

        // Skip if already returned, pending, or approved
        if (in_array($line->return_status, ['pending', 'approved', 'returned'])) {
            return false;
        }

        // Skip if delivery status is returned
        if ($line->delivery_status === 'returned') {
            return false;
        }

        // Check available quantity
        $purchasedQty = (int) $line->quantity;
        $returnedQty = (int) ($line->returned_quantity ?? 0);
        $availableQty = $purchasedQty - $returnedQty;

        if ($availableQty <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Validate order line for buyback
     */
    private function validateOrderLineForBuyback($orderLine, $user, string $productName, int $buybackWindow, int $requestedQty): void
    {
        // Check ownership
        if ($orderLine->order->user_id !== $user->id) {
            throw new \Exception("Item '{$productName}' does not belong to you.");
        }

        // Validate order status
        if (!in_array($orderLine->order->status, ['confirmed', 'processing', 'shipped', 'delivered', 'partial_delivered'])) {
            throw new \Exception("Order is not in a valid status for buyback.");
        }

        // Check delivery status
        if ($orderLine->delivery_status !== 'delivered') {
            throw new \Exception("Item '{$productName}' has not been delivered.");
        }

        // Check buy-back window
        if ($orderLine->order->created_at->diffInDays(now()) > $buybackWindow) {
            throw new \Exception("Item '{$productName}' is outside the buy-back window ({$buybackWindow} days from purchase).");
        }

        // Check if already returned
        if ($orderLine->delivery_status === 'returned') {
            throw new \Exception("Item '{$productName}' has already been returned.");
        }

        // Check return status
        if (in_array($orderLine->return_status, ['pending', 'approved', 'returned'])) {
            throw new \Exception("Item '{$productName}' already has a return in progress.");
        }

        // Check existing pending request
        $existingReturn = OrderReturn::where('type', 'buyback')
            ->where('status', 'pending')
            ->whereRaw('JSON_CONTAINS(items, JSON_OBJECT("order_line_id", ?))', [$orderLine->id])
            ->exists();

        if ($existingReturn) {
            throw new \Exception("Item '{$productName}' already has a pending buyback request.");
        }

        // Check available quantity
        $purchasedQty = (int) $orderLine->quantity;
        $returnedQty = (int) ($orderLine->returned_quantity ?? 0);
        $availableQty = $purchasedQty - $returnedQty;

        if ($availableQty <= 0) {
            throw new \Exception("No available quantity for '{$productName}'. Purchased: {$purchasedQty}, Already returned: {$returnedQty}");
        }

        if ($requestedQty > $availableQty) {
            throw new \Exception("Only {$availableQty} units of '{$productName}' are available. You requested {$requestedQty}.");
        }
    }

    private function updateOrderReturnStatus($order): void
    {
        $lines = $order->lines;
        $deliverableLines = $lines->whereIn('delivery_status', ['delivered', 'partial_delivered', 'return_initiated', 'return_pending']);
        $deliverableCount = $deliverableLines->count();

        if ($deliverableCount === 0) {
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

        foreach ($deliverableLines as $line) {
            $status = $line->return_status ?? 'none';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        $returnStatus = 'none';
        if ($statusCounts['returned'] === $deliverableCount) {
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