<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\AdminNotification;
use App\Services\PaymentGateway\RazorpayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class ReturnService
{
    protected RazorpayService $razorpayService;

    public function __construct(RazorpayService $razorpayService)
    {
        $this->razorpayService = $razorpayService;
    }

    /**
     * Get order return eligibility
     */
    public function getReturnEligibility(int $userId, string $orderReference): array
    {
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $userId)
            ->with(['lines.product'])
            ->firstOrFail();

        // Check if order is delivered
        if (!$order->delivered_at) {
            return [
                'eligible' => false,
                'message' => 'Order has not been delivered yet. Returns can only be initiated after delivery.',
                'items' => [],
            ];
        }

        // Check 30-day window
        if (!$order->isReturnable()) {
            $daysPassed = $order->delivered_at->diffInDays(now());
            return [
                'eligible' => false,
                'message' => "Return window has expired. Returns must be initiated within 30 days of delivery. ({$daysPassed} days passed)",
                'items' => [],
            ];
        }

        // Check if return already exists
        if ($order->hasPendingReturn()) {
            return [
                'eligible' => false,
                'message' => 'A return request is already pending for this order. Please wait for admin review.',
                'items' => [],
            ];
        }

        if ($order->hasApprovedReturn()) {
            return [
                'eligible' => false,
                'message' => 'This order has already been returned.',
                'items' => [],
            ];
        }

        // Get returnable items
        $returnableItems = [];
        foreach ($order->lines as $line) {
            $available = $line->available_for_return;
            if ($available > 0) {
                $returnableItems[] = [
                    'order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product->name,
                    'product_code' => $line->product->product_code,
                    'unit_price' => (float) $line->unit_price,
                    'quantity' => (int) $line->quantity,
                    'already_returned' => (int) $line->returned_quantity,
                    'available_for_return' => (int) $available,
                    'line_total' => (float) $line->line_total,
                    'gst_rate' => (float) $line->gst_rate,
                    'gst_amount' => (float) $line->gst_amount,
                    'return_reasons' => $this->getReturnReasons(),
                ];
            }
        }

        if (empty($returnableItems)) {
            return [
                'eligible' => false,
                'message' => 'All items in this order have already been returned.',
                'items' => [],
            ];
        }

        return [
            'eligible' => true,
            'order_reference' => $order->order_reference,
            'delivered_at' => $order->delivered_at->toDateTimeString(),
            'days_since_delivery' => $order->delivered_at->diffInDays(now()),
            'items' => $returnableItems,
        ];
    }

    /**
     * Initiate return request
     */
    public function initiateReturn(int $userId, array $data): array
    {
        $validator = Validator::make($data, [
            'order_reference' => 'required|string|exists:orders,order_reference',
            'items' => 'required|array|min:1',
            'items.*.order_line_id' => 'required|exists:order_lines,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'required|string|max:500',
            'return_reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->errors()->first());
        }

        $order = Order::where('order_reference', $data['order_reference'])
            ->where('user_id', $userId)
            ->with('lines')
            ->firstOrFail();

        // Validate order eligibility
        if (!$order->delivered_at) {
            throw new Exception('Order has not been delivered yet.');
        }

        if (!$order->isReturnable()) {
            throw new Exception('Return window has expired (30 days from delivery).');
        }

        if ($order->hasPendingReturn()) {
            throw new Exception('A return request is already pending for this order.');
        }

        if ($order->hasApprovedReturn()) {
            throw new Exception('This order has already been returned.');
        }

        // Validate items
        $returnItems = [];
        $refundSubtotal = 0;
        $refundTax = 0;
        $refundShipping = 0;

        foreach ($data['items'] as $itemData) {
            $orderLine = $order->lines()->find($itemData['order_line_id']);
            if (!$orderLine) {
                throw new Exception("Invalid order line ID: {$itemData['order_line_id']}");
            }

            // Check if quantity is available for return
            $available = $orderLine->available_for_return;
            if ($itemData['quantity'] > $available) {
                throw new Exception("Only {$available} units of '{$orderLine->product->name}' are available for return.");
            }

            // Calculate refund amounts
            $unitPrice = (float) $orderLine->unit_price;
            $quantity = (int) $itemData['quantity'];
            $subtotal = $unitPrice * $quantity;
            $tax = $orderLine->gst_rate ? ($subtotal * $orderLine->gst_rate / 100) : 0;

            $returnItems[] = [
                'order_line_id' => $orderLine->id,
                'product_id' => $orderLine->product_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'reason' => $itemData['reason'],
            ];

            $refundSubtotal += $subtotal;
            $refundTax += $tax;
        }

        // Calculate shipping refund proportionally
        if ((float) $order->shipping_charge > 0) {
            $orderSubtotal = (float) $order->subtotal;
            $returnedProportion = $refundSubtotal / $orderSubtotal;
            $refundShipping = (float) $order->shipping_charge * $returnedProportion;
        }

        $totalRefund = $refundSubtotal + $refundTax + $refundShipping;

        // Create return request
        return DB::transaction(function () use ($order, $userId, $returnItems, $refundSubtotal, $refundTax, $refundShipping, $totalRefund, $data) {
            $returnOrder = OrderReturn::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'items' => $returnItems,
                'status' => OrderReturn::STATUS_PENDING,
                'reason' => $data['return_reason'] ?? null,
                'refund_subtotal' => round($refundSubtotal, 2),
                'refund_tax' => round($refundTax, 2),
                'refund_shipping' => round($refundShipping, 2),
                'total_refund_amount' => round($totalRefund, 2),
                'total_cv_reversed' => $this->calculateCvReversal($order, $returnItems),
            ]);

            // Update order lines
            foreach ($returnItems as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                $orderLine->update([
                    'returned_quantity' => $orderLine->returned_quantity + $item['quantity'],
                    'return_status' => 'pending',
                ]);
            }

            // Update order status
            $order->update([
                'return_status' => 'pending',
            ]);

            // Create admin notification
            $this->createReturnNotification($returnOrder, 'pending');

            Log::info('Return request initiated', [
                'return_id' => $returnOrder->id,
                'order_reference' => $order->order_reference,
                'user_id' => $userId,
                'total_refund' => $totalRefund,
            ]);

            return [
                'success' => true,
                'return_id' => $returnOrder->id,
                'status' => 'pending',
                'message' => 'Return request submitted successfully. Admin will review and notify you.',
                'refund_details' => [
                    'subtotal' => round($refundSubtotal, 2),
                    'tax' => round($refundTax, 2),
                    'shipping' => round($refundShipping, 2),
                    'total' => round($totalRefund, 2),
                ],
                'items' => $returnItems,
            ];
        });
    }

    /**
     * Admin: Get all return requests
     */
    public function getReturnsForAdmin(?string $status = null): array
    {
        $query = OrderReturn::with(['order', 'user'])
            ->orderBy('created_at', 'desc');

        if ($status && in_array($status, ['pending', 'approved', 'rejected', 'received', 'completed'])) {
            $query->where('status', $status);
        }

        $returns = $query->get();

        return [
            'total' => $returns->count(),
            'pending' => $returns->where('status', 'pending')->count(),
            'approved' => $returns->where('status', 'approved')->count(),
            'rejected' => $returns->where('status', 'rejected')->count(),
            'completed' => $returns->where('status', 'completed')->count(),
            'data' => $returns->map(function ($return) {
                return [
                    'id' => $return->id,
                    'order_reference' => $return->order->order_reference ?? null,
                    'user' => $return->user ? [
                        'id' => $return->user->id,
                        'name' => $return->user->name,
                        'email' => $return->user->email,
                    ] : null,
                    'status' => $return->status,
                    'items_count' => count($return->items),
                    'refund_amount' => (float) $return->total_refund_amount,
                    'reason' => $return->reason,
                    'created_at' => $return->created_at->toDateTimeString(),
                    'can_approve' => $return->canApprove(),
                    'can_reject' => $return->canReject(),
                ];
            }),
        ];
    }

    /**
     * Get user's return requests
     */
    public function getUserReturns(int $userId): array
    {
        $returns = OrderReturn::where('user_id', $userId)
            ->with('order')
            ->orderBy('created_at', 'desc')
            ->get();

        return $returns->map(function ($return) {
            return [
                'id' => $return->id,
                'order_reference' => $return->order->order_reference ?? null,
                'status' => $return->status,
                'items' => $return->return_items_with_details,
                'refund_amount' => (float) $return->total_refund_amount,
                'reason' => $return->reason,
                'admin_notes' => $return->admin_notes,
                'rejection_reason' => $return->rejection_reason,
                'created_at' => $return->created_at->toDateTimeString(),
                'approved_at' => $return->approved_at?->toDateTimeString(),
                'completed_at' => $return->completed_at?->toDateTimeString(),
            ];
        })->toArray();
    }

    /**
     * Get single return details for user
     */
    public function getUserReturn(int $userId, int $returnId): ?array
    {
        $return = OrderReturn::with(['order'])
            ->where('user_id', $userId)
            ->find($returnId);

        if (!$return) {
            return null;
        }

        return [
            'id' => $return->id,
            'order_reference' => $return->order->order_reference ?? null,
            'status' => $return->status,
            'items' => $return->return_items_with_details,
            'refund_details' => [
                'subtotal' => (float) $return->refund_subtotal,
                'tax' => (float) $return->refund_tax,
                'shipping' => (float) $return->refund_shipping,
                'total' => (float) $return->total_refund_amount,
            ],
            'reason' => $return->reason,
            'admin_notes' => $return->admin_notes,
            'rejection_reason' => $return->rejection_reason,
            'created_at' => $return->created_at->toDateTimeString(),
            'approved_at' => $return->approved_at?->toDateTimeString(),
            'completed_at' => $return->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * Admin: Get single return details
     */
    public function getReturnForAdmin(int $returnId): array
    {
        $return = OrderReturn::with(['order', 'user', 'order.lines.product'])
            ->findOrFail($returnId);

        return [
            'id' => $return->id,
            'order' => $return->order ? [
                'id' => $return->order->id,
                'order_reference' => $return->order->order_reference,
                'status' => $return->order->status,
                'return_status' => $return->order->return_status,
                'delivered_at' => $return->order->delivered_at?->toDateTimeString(),
            ] : null,
            'user' => $return->user ? [
                'id' => $return->user->id,
                'name' => $return->user->name,
                'email' => $return->user->email,
                'phone' => $return->user->phone ?? null,
            ] : null,
            'status' => $return->status,
            'items' => $return->return_items_with_details,
            'refund_details' => [
                'subtotal' => (float) $return->refund_subtotal,
                'tax' => (float) $return->refund_tax,
                'shipping' => (float) $return->refund_shipping,
                'total' => (float) $return->total_refund_amount,
            ],
            'reason' => $return->reason,
            'admin_notes' => $return->admin_notes,
            'rejection_reason' => $return->rejection_reason,
            'created_at' => $return->created_at->toDateTimeString(),
            'approved_at' => $return->approved_at?->toDateTimeString(),
            'received_at' => $return->received_at?->toDateTimeString(),
            'completed_at' => $return->completed_at?->toDateTimeString(),
            'can_approve' => $return->canApprove(),
            'can_reject' => $return->canReject(),
            'can_mark_received' => $return->canMarkReceived(),
            'can_complete' => $return->canComplete(),
        ];
    }

    /**
     * Admin: Approve return request
     */
    public function approveReturn(int $returnId, int $adminId, ?string $adminNotes = null): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->findOrFail($returnId);

        if (!$returnOrder->canApprove()) {
            throw new Exception('This return request cannot be approved (already processed).');
        }

        return DB::transaction(function () use ($returnOrder, $adminId, $adminNotes) {
            $returnOrder->update([
                'status' => OrderReturn::STATUS_APPROVED,
                'admin_id' => $adminId,
                'admin_notes' => $adminNotes,
                'approved_at' => now(),
            ]);

            // Update order status
            $returnOrder->order->update([
                'return_status' => 'partial_approved',
            ]);

            // Update order lines status
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine) {
                    $orderLine->update(['return_status' => 'approved']);
                }
            }

            // Create notification for admin
            $this->createReturnNotification($returnOrder, 'approved');

            // Send notification to user
            $this->sendUserNotification($returnOrder, 'approved');

            Log::info('Return approved', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'refund_amount' => $returnOrder->total_refund_amount,
            ]);

            return [
                'success' => true,
                'message' => 'Return request approved successfully.',
                'return_id' => $returnOrder->id,
                'status' => 'approved',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'admin_notes' => $adminNotes,
            ];
        });
    }

    /**
     * Admin: Reject return request
     */
    public function rejectReturn(int $returnId, int $adminId, string $rejectionReason): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->findOrFail($returnId);

        if (!$returnOrder->canReject()) {
            throw new Exception('This return request cannot be rejected (already processed).');
        }

        return DB::transaction(function () use ($returnOrder, $adminId, $rejectionReason) {
            $returnOrder->update([
                'status' => OrderReturn::STATUS_REJECTED,
                'admin_id' => $adminId,
                'rejection_reason' => $rejectionReason,
            ]);

            // Update order status
            $returnOrder->order->update([
                'return_status' => 'rejected',
            ]);

            // Update order lines status
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine) {
                    $orderLine->update(['return_status' => 'rejected']);
                }
            }

            // Create notification for admin
            $this->createReturnNotification($returnOrder, 'rejected');

            // Send notification to user
            $this->sendUserNotification($returnOrder, 'rejected');

            Log::info('Return rejected', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'reason' => $rejectionReason,
            ]);

            return [
                'success' => true,
                'message' => 'Return request rejected.',
                'return_id' => $returnOrder->id,
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
            ];
        });
    }

    /**
     * Admin: Mark return as received
     */
    public function markReturnReceived(int $returnId): array
    {
        $returnOrder = OrderReturn::findOrFail($returnId);

        if (!$returnOrder->canMarkReceived()) {
            throw new Exception('Only approved returns can be marked as received.');
        }

        $returnOrder->update([
            'status' => OrderReturn::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->createReturnNotification($returnOrder, 'received');

        Log::info('Return marked as received', [
            'return_id' => $returnOrder->id,
        ]);

        return [
            'success' => true,
            'message' => 'Return marked as received.',
            'return_id' => $returnOrder->id,
            'status' => 'received',
        ];
    }

    /**
     * Admin: Complete return (refund processed)
     */
    public function completeReturn(int $returnId): array
    {
        $returnOrder = OrderReturn::with(['order'])
            ->findOrFail($returnId);

        if (!$returnOrder->canComplete()) {
            throw new Exception('Return cannot be completed. Status must be "received".');
        }

        return DB::transaction(function () use ($returnOrder) {
            // Process refund if not already processed
            if (!$returnOrder->refund_processed_at) {
                $this->processRefund($returnOrder);
            }

            $returnOrder->update([
                'status' => OrderReturn::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // Update order
            $returnOrder->order->update([
                'return_status' => 'fully_approved',
            ]);

            // Update order lines
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine) {
                    $orderLine->update(['return_status' => 'returned']);
                }
            }

            $this->createReturnNotification($returnOrder, 'completed');

            Log::info('Return completed', [
                'return_id' => $returnOrder->id,
            ]);

            return [
                'success' => true,
                'message' => 'Return completed successfully.',
                'return_id' => $returnOrder->id,
                'status' => 'completed',
            ];
        });
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get available return reasons
     */
    protected function getReturnReasons(): array
    {
        return [
            'damaged' => 'Product damaged or defective',
            'wrong_item' => 'Wrong item received',
            'not_as_described' => 'Product not as described',
            'size_issue' => 'Size/Fit issue',
            'quality_issue' => 'Quality issue',
            'changed_mind' => 'Changed mind / no longer needed',
            'other' => 'Other reason',
        ];
    }

    /**
     * Calculate CV reversal for return
     */
    protected function calculateCvReversal(Order $order, array $returnItems): float
    {
        $totalCv = 0;
        foreach ($returnItems as $item) {
            $orderLine = OrderLine::find($item['order_line_id']);
            if ($orderLine && $orderLine->commissionable_volume) {
                $cvPerUnit = (float) $orderLine->commissionable_volume / (float) $orderLine->quantity;
                $totalCv += $cvPerUnit * (int) $item['quantity'];
            }
        }
        return round($totalCv, 2);
    }

    /**
     * Process refund for return
     */
    protected function processRefund(OrderReturn $returnOrder): void
    {
        $gateway = $returnOrder->order->payment_gateway ?? 'razorpay';
        $refundAmount = (float) $returnOrder->total_refund_amount;

        if ($refundAmount <= 0) {
            Log::warning('Refund amount is zero or negative', [
                'return_id' => $returnOrder->id,
            ]);
            return;
        }

        if ($gateway === 'razorpay' && $returnOrder->order->gateway_transaction_id) {
            try {
                $this->razorpayService->refundPayment(
                    $returnOrder->order->gateway_transaction_id,
                    $refundAmount
                );
            } catch (\Exception $e) {
                Log::error('Refund failed for return', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                ]);
                throw new Exception('Failed to process refund: ' . $e->getMessage());
            }
        }

        $returnOrder->update([
            'refund_processed_at' => now(),
        ]);

        Log::info('Refund processed for return', [
            'return_id' => $returnOrder->id,
            'amount' => $refundAmount,
        ]);
    }

    /**
     * Create admin notification for return
     */
    protected function createReturnNotification(OrderReturn $returnOrder, string $status): void
    {
        $messages = [
            'pending' => [
                'title' => 'New Return Request',
                'message' => "Order #{$returnOrder->order->order_reference} has a new return request. Total refund amount: ₹{$returnOrder->total_refund_amount}",
                'priority' => 'high',
            ],
            'approved' => [
                'title' => 'Return Request Approved',
                'message' => "Return for Order #{$returnOrder->order->order_reference} has been approved. Refund amount: ₹{$returnOrder->total_refund_amount}",
                'priority' => 'medium',
            ],
            'rejected' => [
                'title' => 'Return Request Rejected',
                'message' => "Return for Order #{$returnOrder->order->order_reference} has been rejected.",
                'priority' => 'medium',
            ],
            'received' => [
                'title' => 'Return Items Received',
                'message' => "Return for Order #{$returnOrder->order->order_reference} items have been received.",
                'priority' => 'medium',
            ],
            'completed' => [
                'title' => 'Return Completed',
                'message' => "Return for Order #{$returnOrder->order->order_reference} has been completed. Refund processed.",
                'priority' => 'low',
            ],
        ];

        $notification = $messages[$status] ?? $messages['pending'];

        AdminNotification::create([
            'admin_id' => 1, // Main admin - you can modify to send to multiple admins
            'type' => 'return_' . $status,
            'title' => $notification['title'],
            'message' => $notification['message'],
            'reference_type' => 'return',
            'reference_id' => $returnOrder->id,
            'priority' => $notification['priority'],
            'extra_data' => json_encode([
                'order_reference' => $returnOrder->order->order_reference ?? null,
                'user_id' => $returnOrder->user_id,
                'total_refund' => (float) $returnOrder->total_refund_amount,
                'items_count' => count($returnOrder->items),
                'status' => $returnOrder->status,
                'return_id' => $returnOrder->id,
            ]),
        ]);
    }

    /**
     * Send notification to user
     */
    protected function sendUserNotification(OrderReturn $returnOrder, string $status): void
    {
        // TODO: Implement email/SMS notification
        // This could use Laravel's notification system
        Log::info('User notification sent', [
            'return_id' => $returnOrder->id,
            'user_id' => $returnOrder->user_id,
            'status' => $status,
        ]);
    }
}
