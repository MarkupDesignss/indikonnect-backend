<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\AdminNotification;
use App\Models\CommissionApiEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\PaymentGateway\RazorpayService;
use App\Services\Commission\CommissionServiceInterface;
use App\Services\Commission\ReversalPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class BuybackService
{
    protected RazorpayService $razorpayService;
    protected CommissionServiceInterface $commissionService;

    public function __construct(
        RazorpayService $razorpayService,
        CommissionServiceInterface $commissionService
    ) {
        $this->razorpayService = $razorpayService;
        $this->commissionService = $commissionService;
    }

    /**
     * Get all buyback requests for admin
     */
    public function getBuybackRequestsForAdmin(?string $status = null, int $perPage = 20): array
    {
        $query = OrderReturn::with(['order', 'user'])
            ->where('type', 'buyback')
            ->orderBy('created_at', 'desc');

        if ($status && in_array($status, ['pending', 'approved', 'rejected', 'received', 'completed'])) {
            $query->where('status', $status);
        }

        $returns = $query->paginate($perPage);

        return [
            'total' => $returns->total(),
            'pending' => OrderReturn::where('type', 'buyback')->where('status', 'pending')->count(),
            'approved' => OrderReturn::where('type', 'buyback')->where('status', 'approved')->count(),
            'rejected' => OrderReturn::where('type', 'buyback')->where('status', 'rejected')->count(),
            'received' => OrderReturn::where('type', 'buyback')->where('status', 'received')->count(),
            'completed' => OrderReturn::where('type', 'buyback')->where('status', 'completed')->count(),
            'data' => $returns->map(function ($return) {
                return [
                    'id' => $return->id,
                    'order_reference' => $return->order->order_reference ?? null,
                    'user' => $return->user ? [
                        'id' => $return->user->id,
                        'name' => $return->user->name,
                        'email' => $return->user->email,
                        'distributor_status' => $return->user->distributor_status ?? null,
                    ] : null,
                    'status' => $return->status,
                    'items_count' => count($return->items),
                    'refund_amount' => (float) $return->total_refund_amount,
                    'deduction_amount' => (float) ($return->extra_data['deduction_amount'] ?? 0),
                    'deduction_percent' => (float) ($return->extra_data['deduction_percent'] ?? 0),
                    'reason' => $return->reason,
                    'declarations' => isset($return->extra_data['declares_marketable']) ? [
                        'marketable' => (bool) ($return->extra_data['declares_marketable'] ?? false),
                        'unsold' => (bool) ($return->extra_data['declares_unsold'] ?? false),
                        'unused' => (bool) ($return->extra_data['declares_unused'] ?? false),
                    ] : null,
                    'created_at' => $return->created_at->toDateTimeString(),
                    'can_approve' => $return->canApprove(),
                    'can_reject' => $return->canReject(),
                    'can_mark_received' => $return->canMarkReceived(),
                    'can_complete' => $return->canComplete(),
                ];
            }),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
                'last_page' => $returns->lastPage(),
            ],
        ];
    }

    /**
     * Get single buyback request for admin
     */
    public function getBuybackForAdmin(int $returnId): array
    {
        $return = OrderReturn::with(['order', 'user', 'order.lines.product'])
            ->where('type', 'buyback')
            ->findOrFail($returnId);

        $itemsWithDetails = [];
        foreach ($return->items as $item) {
            $orderLine = OrderLine::with('product')->find($item['order_line_id']);

            $itemsWithDetails[] = [
                'order_line_id' => $item['order_line_id'],
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'] ?? 'Unknown',
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'gst_rate' => (float) ($item['gst_rate'] ?? 0),
                'subtotal' => (float) ($item['subtotal'] ?? 0),
                'tax' => (float) ($item['tax'] ?? 0),
                'line_total' => (float) ($item['line_total'] ?? 0),
                'reason' => $item['reason'] ?? null,
                'image_paths' => $item['image_paths'] ?? [],
                'return_status' => $item['return_status'] ?? 'pending',
                'commissionable_volume_reversed' => (float) ($item['commissionable_volume_reversed'] ?? 0),
                'product' => $orderLine && $orderLine->product ? [
                    'id' => $orderLine->product->id,
                    'name' => $orderLine->product->name,
                    'product_code' => $orderLine->product->product_code,
                    'category' => $orderLine->product->category ?? null,
                ] : null,
                'original_quantity' => $orderLine ? (int) $orderLine->quantity : 0,
                'already_returned' => $orderLine ? (int) ($orderLine->returned_quantity ?? 0) : 0,
            ];
        }

        return [
            'id' => $return->id,
            'order' => $return->order ? [
                'id' => $return->order->id,
                'order_reference' => $return->order->order_reference,
                'status' => $return->order->status,
                'return_status' => $return->order->return_status,
                'delivery_status' => $return->order->delivery_status,
                'subtotal' => (float) $return->order->subtotal,
                'shipping_charge' => (float) $return->order->shipping_charge,
                'total' => (float) $return->order->total,
                'delivered_at' => $return->order->delivered_at?->toDateTimeString(),
                'created_at' => $return->order->created_at?->toDateTimeString(),
            ] : null,
            'user' => $return->user ? [
                'id' => $return->user->id,
                'name' => $return->user->name,
                'email' => $return->user->email,
                'phone' => $return->user->phone ?? null,
                'account_type' => $return->user->account_type,
                'distributor_status' => $return->user->distributor_status ?? null,
            ] : null,
            'type' => $return->type,
            'status' => $return->status,
            'items' => $itemsWithDetails,
            'refund_details' => [
                'subtotal' => (float) $return->refund_subtotal,
                'tax' => (float) $return->refund_tax,
                'shipping' => (float) $return->refund_shipping,
                'total' => (float) $return->total_refund_amount,
                'deduction_amount' => (float) ($return->extra_data['deduction_amount'] ?? 0),
                'deduction_percent' => (float) ($return->extra_data['deduction_percent'] ?? 0),
            ],
            'declarations' => [
                'marketable' => (bool) ($return->extra_data['declares_marketable'] ?? false),
                'unsold' => (bool) ($return->extra_data['declares_unsold'] ?? false),
                'unused' => (bool) ($return->extra_data['declares_unused'] ?? false),
                'declared_at' => $return->extra_data['declared_at'] ?? null,
            ],
            'reason' => $return->reason,
            'admin_notes' => $return->admin_notes,
            'rejection_reason' => $return->rejection_reason,
            'refund_transaction_id' => $return->refund_transaction_id,
            'refund_status' => $return->refund_status,
            'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
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
     * Approve buyback request with Stock Restore and Reversal Trigger
     */
    public function approveBuyback(int $returnId, int $adminId, ?string $adminNotes = null): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->where('type', 'buyback')
            ->findOrFail($returnId);

        if (!$returnOrder->canApprove()) {
            throw new Exception('This buyback request cannot be approved (already processed).');
        }

        return DB::transaction(function () use ($returnOrder, $adminId, $adminNotes) {
            // 1. Update return status
            $returnOrder->update([
                'status' => 'approved',
                'admin_id' => $adminId,
                'admin_notes' => $adminNotes,
                'approved_at' => now(),
            ]);

            // 2. Update individual order lines
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine && $orderLine->return_status === 'pending') {
                    $orderLine->update([
                        'return_status' => 'approved',
                        'delivery_status' => 'return_approved',
                        'return_approved_at' => now(),
                    ]);
                }
            }

            // 3. Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);
            $this->updateOrderMainStatus($returnOrder->order);

            // ========== STOCK RESTORE ON APPROVAL ==========
            try {
                $this->restoreStockForBuyback($returnOrder);
                Log::info('Stock restored successfully for buyback approval', [
                    'return_id' => $returnOrder->id,
                    'order_reference' => $returnOrder->order->order_reference ?? null,
                ]);
            } catch (\Exception $e) {
                Log::error('Stock restore failed for buyback approval', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // ========== BUYBACK REVERSAL TRIGGER ==========
            try {
                $this->triggerBuybackReversal($returnOrder);
                Log::info('Buyback reversal triggered successfully', [
                    'return_id' => $returnOrder->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Buyback reversal trigger failed', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // 4. Admin notification
            $this->createBuybackNotification($returnOrder, 'approved');

            // 5. User notification
            $this->sendUserNotification($returnOrder, 'approved');

            // 6. Logging
            Log::info('Buyback request approved', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'refund_amount' => $returnOrder->total_refund_amount,
                'user_id' => $returnOrder->user_id,
                'stock_restored' => true,
                'reversal_triggered' => true,
            ]);

            return [
                'success' => true,
                'message' => 'Buyback request approved successfully. Stock restored and reversal triggered.',
                'return_id' => $returnOrder->id,
                'order_status' => $returnOrder->order->status,
                'order_return_status' => $returnOrder->order->return_status,
                'status' => 'approved',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'admin_notes' => $adminNotes,
                'stock_restored' => true,
                'reversal_triggered' => true,
            ];
        });
    }

    /**
     * Reject buyback request
     */
    public function rejectBuyback(int $returnId, int $adminId, string $rejectionReason): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->where('type', 'buyback')
            ->findOrFail($returnId);

        if (!$returnOrder->canReject()) {
            throw new Exception('This buyback request cannot be rejected (already processed).');
        }

        return DB::transaction(function () use ($returnOrder, $adminId, $rejectionReason) {
            // 1. Update return status
            $returnOrder->update([
                'status' => 'rejected',
                'admin_id' => $adminId,
                'rejection_reason' => $rejectionReason,
                'rejected_at' => now(),
            ]);

            // 2. Reset order lines
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine && $orderLine->return_status === 'pending') {
                    $currentReturnedQuantity = (int) ($orderLine->returned_quantity ?? 0);
                    $returnQuantity = (int) ($item['quantity'] ?? 0);
                    $newReturnedQuantity = max(0, $currentReturnedQuantity - $returnQuantity);

                    $orderLine->update([
                        'return_status' => 'rejected',
                        'delivery_status' => 'return_rejected',
                        'return_rejected_at' => now(),
                        'return_rejection_reason' => $rejectionReason,
                        'return_requested_at' => null,
                        'returned_quantity' => $newReturnedQuantity,
                    ]);
                }
            }

            // 3. Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);
            $this->updateOrderMainStatus($returnOrder->order);

            // 4. Admin notification
            $this->createBuybackNotification($returnOrder, 'rejected');

            // 5. User notification
            $this->sendUserNotification($returnOrder, 'rejected');

            // 6. Logging
            Log::info('Buyback request rejected', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'reason' => $rejectionReason,
                'user_id' => $returnOrder->user_id,
            ]);

            return [
                'success' => true,
                'message' => 'Buyback request rejected.',
                'return_id' => $returnOrder->id,
                'order_status' => $returnOrder->order->status,
                'order_return_status' => $returnOrder->order->return_status,
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
                'items' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'],
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'return_status' => 'rejected'
                    ];
                }, $returnOrder->items),
            ];
        });
    }

    /**
     * Mark buyback items as received
     */
    public function markBuybackReceived(int $returnId): array
    {
        $returnOrder = OrderReturn::with(['order', 'user', 'order.lines'])
            ->where('type', 'buyback')
            ->findOrFail($returnId);

        if (!$returnOrder->canMarkReceived()) {
            throw new Exception('Only approved buybacks can be marked as received.');
        }

        return DB::transaction(function () use ($returnOrder) {
            // 1. Mark return as received
            $returnOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            // 2. Update order lines
            foreach ($returnOrder->items ?? [] as $item) {
                $orderLine = OrderLine::find($item['order_line_id'] ?? null);
                if ($orderLine && $orderLine->return_status === 'approved') {
                    $orderLine->update([
                        'delivery_status' => 'received',
                    ]);
                }
            }

            // 3. Admin notification
            $this->createBuybackNotification($returnOrder, 'received');

            // 4. Logging
            Log::info('Buyback items marked as received', [
                'return_id' => $returnOrder->id,
                'order_id' => $returnOrder->order_id,
            ]);

            return [
                'success' => true,
                'message' => 'Buyback items marked as received.',
                'return_id' => $returnOrder->id,
                'status' => 'received',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
            ];
        });
    }

    /**
     * Complete buyback request
     */
    public function completeBuyback(int $returnId): array
    {
        $returnOrder = OrderReturn::with(['order'])
            ->where('type', 'buyback')
            ->findOrFail($returnId);

        if (!$returnOrder->canComplete()) {
            throw new Exception('Buyback cannot be completed. Status must be "received".');
        }

        return DB::transaction(function () use ($returnOrder) {
            // Process refund if not already processed
            if (!$returnOrder->refund_processed_at) {
                $this->processRefund($returnOrder);
            }

            // 1. Mark as completed
            $returnOrder->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // 2. Update order lines
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine && $orderLine->return_status === 'approved') {
                    $orderLine->update([
                        'return_status' => 'returned',
                        'delivery_status' => 'returned',
                        'return_completed_at' => now(),
                    ]);
                }
            }

            // 3. Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);
            $this->updateOrderMainStatus($returnOrder->order);

            // 4. Admin notification
            $this->createBuybackNotification($returnOrder, 'completed');

            // 5. User notification
            $this->sendUserNotification($returnOrder, 'completed');

            // 6. Logging
            Log::info('Buyback completed', [
                'return_id' => $returnOrder->id,
                'refund_transaction_id' => $returnOrder->refund_transaction_id,
            ]);

            return [
                'success' => true,
                'message' => 'Buyback completed successfully.',
                'return_id' => $returnOrder->id,
                'status' => 'completed',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'refund_transaction_id' => $returnOrder->refund_transaction_id,
            ];
        });
    }

    /**
     * Get buyback summary statistics
     */
    public function getBuybackSummary(): array
    {
        $returns = OrderReturn::where('type', 'buyback')->get();

        return [
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
        ];
    }

    // ========== STOCK RESTORE ==========

    /**
     * Restore stock for buyback approval
     * Handles both simple products and product variants
     */
    protected function restoreStockForBuyback(OrderReturn $returnOrder): void
    {
        $items = $returnOrder->items ?? [];
        $order = $returnOrder->order;

        Log::info('Starting stock restore for buyback', [
            'return_id' => $returnOrder->id,
            'order_reference' => $order->order_reference ?? null,
            'items_count' => count($items),
        ]);

        foreach ($items as $item) {
            $orderLine = OrderLine::find($item['order_line_id']);
            if (!$orderLine) {
                Log::warning('Order line not found for stock restore', [
                    'return_id' => $returnOrder->id,
                    'order_line_id' => $item['order_line_id'],
                ]);
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            $productId = $orderLine->product_id;
            $variantId = $orderLine->variant_id;

            if ($quantity <= 0) {
                continue;
            }

            // Restore stock based on variant or product
            if ($variantId) {
                $variant = ProductVariant::find($variantId);
                if ($variant) {
                    $variant->stock_quantity += $quantity;
                    $variant->save();

                    Log::info('Variant stock restored for buyback', [
                        'return_id' => $returnOrder->id,
                        'variant_id' => $variantId,
                        'sku' => $variant->sku,
                        'quantity_restored' => $quantity,
                        'new_stock' => $variant->stock_quantity,
                    ]);
                } else {
                    Log::warning('Variant not found for stock restore', [
                        'variant_id' => $variantId,
                    ]);
                }
            } else {
                $product = Product::find($productId);
                if ($product) {
                    $product->stock_quantity += $quantity;
                    $product->save();

                    Log::info('Product stock restored for buyback', [
                        'return_id' => $returnOrder->id,
                        'product_id' => $productId,
                        'product_name' => $product->name,
                        'quantity_restored' => $quantity,
                        'new_stock' => $product->stock_quantity,
                    ]);
                } else {
                    Log::warning('Product not found for stock restore', [
                        'product_id' => $productId,
                    ]);
                }
            }

            // Create stock movement record
            $availableAfter = $variantId
                ? (ProductVariant::find($variantId)?->stock_quantity ?? 0)
                : (Product::find($productId)?->stock_quantity ?? 0);

            StockMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'available_quantity_after' => $availableAfter,
                'reason' => 'Buy-back approved: ' . ($order->order_reference ?? ''),
                'admin_id' => auth()->id() ?? 1,
                'order_id' => $order->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update order line returned_quantity
            $orderLine->returned_quantity += $quantity;
            $orderLine->save();
        }

        Log::info('Stock restore completed for buyback', [
            'return_id' => $returnOrder->id,
            'items_restored' => count($items),
        ]);
    }

    // ========== BUYBACK REVERSAL TRIGGER ==========

    /**
     * Trigger buyback reversal for Commission API
     */
    protected function triggerBuybackReversal(OrderReturn $returnOrder): void
    {
        $order = $returnOrder->order;

        if (!$order) {
            Log::error('Order not found for buyback reversal', [
                'return_id' => $returnOrder->id,
            ]);
            return;
        }

        // Build payload
        $payload = [
            'eventId' => 'evt_' . Str::random(24),
            'action' => 'REVERSAL',
            'orderReference' => $order->order_reference,
            'reason' => 'Buy-back request approved by admin',
            'lines' => $this->buildReversalLines($returnOrder),
            'reversedValue' => (float) $returnOrder->total_refund_amount,
            'originalCv' => (float) ($order->commissionable_volume ?? 0),
            'purchaserIdentifier' => (string) $returnOrder->user_id,
            'accountType' => 'DISTRIBUTOR',
            'eventTimestamp' => now()->toIso8601String(),
        ];

        // Save event in database
        $event = CommissionApiEvent::create([
            'event_type' => 'reversal',
            'order_id' => $order->id,
            'return_id' => $returnOrder->id,
            'payload' => json_encode($payload),
            'status' => 'pending',
            'retry_count' => 0,
            'max_retries' => 5,
        ]);

        Log::info('Buyback reversal event saved in database', [
            'event_id' => $event->id,
            'return_id' => $returnOrder->id,
            'order_reference' => $order->order_reference,
        ]);

        // Send to Commission API
        try {
            $reversalPayload = new ReversalPayload(
                eventId: $payload['eventId'],
                action: $payload['action'],
                orderReference: $payload['orderReference'],
                reason: $payload['reason'],
                lines: $payload['lines'],
                reversedValue: $payload['reversedValue'],
                originalCv: $payload['originalCv'],
                purchaserIdentifier: $payload['purchaserIdentifier'],
                accountType: $payload['accountType'],
                eventTimestamp: $payload['eventTimestamp'],
            );

            $this->commissionService->postReversalEvent($reversalPayload);
            $event->update(['status' => 'sent']);

            Log::info('Buyback reversal event posted successfully', [
                'return_id' => $returnOrder->id,
                'event_id' => $event->id,
            ]);
        } catch (\Exception $e) {
            $event->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'last_attempt' => now(),
            ]);

            Log::error('Failed to send buyback reversal to Commission API', [
                'return_id' => $returnOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Build reversal lines for Commission API
     */
    protected function buildReversalLines(OrderReturn $returnOrder): array
    {
        $lines = [];
        $items = $returnOrder->items ?? [];

        foreach ($items as $item) {
            $orderLine = OrderLine::find($item['order_line_id']);
            if (!$orderLine) {
                Log::warning('Order line not found for reversal lines', [
                    'return_id' => $returnOrder->id,
                    'order_line_id' => $item['order_line_id'] ?? null,
                ]);
                continue;
            }

            $lines[] = [
                'productIdentifier' => (string) $orderLine->product_id,
                'quantity' => (int) $item['quantity'],
                'unitPriceCharged' => number_format((float) $orderLine->unit_price, 2, '.', ''),
                'taxCategory' => 'GST',
            ];
        }

        return $lines;
    }

    // ========== REFUND PROCESSING ==========

    /**
     * Process refund for buyback
     */
    protected function processRefund(OrderReturn $returnOrder): array
    {
        $order = $returnOrder->order;

        if (!$order) {
            throw new Exception('Order not found for this buyback.');
        }

        $gateway = $order->payment_gateway ?? 'razorpay';
        $paymentId = $order->gateway_transaction_id;

        // Calculate refund amount from returned items only
        $refundAmount = $this->calculateRefundAmountFromItems($returnOrder);

        if ($refundAmount <= 0) {
            throw new Exception('Refund amount must be greater than zero.');
        }

        if ($gateway !== 'razorpay') {
            throw new Exception('Refund is not supported for payment gateway: ' . $gateway);
        }

        if (empty($paymentId)) {
            throw new Exception('Razorpay payment ID is missing for this order.');
        }

        try {
            Log::info('Starting Razorpay refund for buyback', [
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'refund_amount' => $refundAmount,
                'amount_in_paise' => (int) round($refundAmount * 100),
            ]);

            $refundResponse = $this->razorpayService->refundPayment($paymentId, $refundAmount);

            if (!is_array($refundResponse) || empty($refundResponse['refund_id'])) {
                throw new Exception('Razorpay refund failed. No refund ID was returned.');
            }

            $returnOrder->update([
                'refund_transaction_id' => $refundResponse['refund_id'],
                'refund_status' => $refundResponse['status'] ?? 'processing',
                'refund_processed_at' => now(),
            ]);

            Log::info('Refund successfully processed via Razorpay for buyback', [
                'return_id' => $returnOrder->id,
                'payment_id' => $paymentId,
                'refund_id' => $refundResponse['refund_id'],
                'refund_status' => $refundResponse['status'] ?? null,
                'amount' => $refundAmount,
            ]);

            return $refundResponse;
        } catch (\Throwable $e) {
            Log::error('Refund failed for buyback', [
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to process refund: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Calculate refund amount from items
     */
    protected function calculateRefundAmountFromItems(OrderReturn $returnOrder): float
    {
        $total = 0.00;
        $items = $returnOrder->items ?? [];

        foreach ($items as $item) {
            $lineTotal = is_array($item)
                ? ($item['line_total'] ?? 0)
                : ($item->line_total ?? 0);
            $total += (float) $lineTotal;
        }

        $shipping = (float) ($returnOrder->refund_shipping ?? 0);
        return round($total + $shipping, 2);
    }

    // ========== HELPER METHODS ==========

    /**
     * Update order return status
     */
    protected function updateOrderReturnStatus(Order $order): void
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

    /**
     * Update order main status
     */
    protected function updateOrderMainStatus(Order $order): void
    {
        $lines = $order->lines()->get();

        if ($lines->isEmpty()) {
            $order->update(['status' => 'pending']);
            return;
        }

        $activeLines = $lines->filter(function ($line) {
            return $line->delivery_status !== 'cancelled';
        });

        $activeCount = $activeLines->count();

        if ($activeCount === 0) {
            $order->update(['status' => 'cancelled']);
            return;
        }

        $returnPendingCount = $activeLines
            ->whereIn('delivery_status', ['return_pending', 'returned'])
            ->count();

        $deliveredCount = $activeLines
            ->where('delivery_status', 'delivered')
            ->count();

        $shippedCount = $activeLines
            ->where('delivery_status', 'shipped')
            ->count();

        if ($returnPendingCount === $activeCount) {
            $finalStatus = 'returned';
        } elseif ($returnPendingCount > 0) {
            $finalStatus = 'partial_returned';
        } elseif ($deliveredCount === $activeCount) {
            $finalStatus = 'delivered';
        } elseif ($deliveredCount > 0) {
            $finalStatus = 'partial_delivered';
        } elseif ($shippedCount === $activeCount) {
            $finalStatus = 'shipped';
        } elseif ($shippedCount > 0) {
            $finalStatus = 'partial_shipped';
        } else {
            $finalStatus = 'pending';
        }

        $order->update(['status' => $finalStatus]);
    }

    /**
     * Create admin notification for buyback
     */
    protected function createBuybackNotification(OrderReturn $returnOrder, string $status): void
    {
        $messages = [
            'pending' => [
                'title' => 'New Buyback Request',
                'message' => "Buyback request for Order #{$returnOrder->order->order_reference} has been submitted. Total refund: ₹{$returnOrder->total_refund_amount}",
                'priority' => 'high',
            ],
            'approved' => [
                'title' => 'Buyback Request Approved',
                'message' => "Buyback for Order #{$returnOrder->order->order_reference} has been approved. Refund amount: ₹{$returnOrder->total_refund_amount}",
                'priority' => 'medium',
            ],
            'rejected' => [
                'title' => 'Buyback Request Rejected',
                'message' => "Buyback for Order #{$returnOrder->order->order_reference} has been rejected.",
                'priority' => 'medium',
            ],
            'received' => [
                'title' => 'Buyback Items Received',
                'message' => "Buyback items for Order #{$returnOrder->order->order_reference} have been received.",
                'priority' => 'medium',
            ],
            'completed' => [
                'title' => 'Buyback Completed',
                'message' => "Buyback for Order #{$returnOrder->order->order_reference} has been completed.",
                'priority' => 'low',
            ],
        ];

        $notification = $messages[$status] ?? $messages['pending'];

        AdminNotification::create([
            'admin_id' => 1,
            'type' => 'buyback_' . $status,
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
                'type' => 'buyback',
            ]),
        ]);
    }

    /**
     * Send notification to user
     */
    protected function sendUserNotification(OrderReturn $returnOrder, string $status): void
    {
        Log::info('User notification sent for buyback', [
            'return_id' => $returnOrder->id,
            'user_id' => $returnOrder->user_id,
            'status' => $status,
        ]);
    }
}