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
use Carbon\Carbon;

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
    // public function initiateReturn(int $userId, array $data): array
    // {
    //     $validator = Validator::make($data, [
    //         'order_reference' => 'required|string|exists:orders,order_reference',
    //         'items' => 'required|array|min:1',
    //         'items.*.order_line_id' => 'required|exists:order_lines,id',
    //         'items.*.quantity' => 'required|integer|min:1',
    //         'items.*.reason' => 'nullable|string|max:500',
    //         'return_reason' => 'nullable|string|max:1000',
    //     ]);

    //     if ($validator->fails()) {
    //         throw new Exception($validator->errors()->first());
    //     }

    //     $order = Order::where('order_reference', $data['order_reference'])
    //         ->where('user_id', $userId)
    //         ->with('lines')
    //         ->firstOrFail();

    //     // Validate order eligibility
    //     if (!$order->delivered_at) {
    //         throw new Exception('Order has not been delivered yet.');
    //     }

    //     if (!$order->isReturnable()) {
    //         throw new Exception('Return window has expired (30 days from delivery).');
    //     }

    //     if ($order->hasPendingReturn()) {
    //         throw new Exception('A return request is already pending for this order.');
    //     }

    //     if ($order->hasApprovedReturn()) {
    //         throw new Exception('This order has already been returned.');
    //     }

    //     // Validate items
    //     $returnItems = [];
    //     $refundSubtotal = 0;
    //     $refundTax = 0;
    //     $refundShipping = 0;

    //     foreach ($data['items'] as $itemData) {
    //         $orderLine = $order->lines()->find($itemData['order_line_id']);
    //         if (!$orderLine) {
    //             throw new Exception("Invalid order line ID: {$itemData['order_line_id']}");
    //         }

    //         // Check if quantity is available for return
    //         $available = $orderLine->available_for_return;
    //         if ($itemData['quantity'] > $available) {
    //             throw new Exception("Only {$available} units of '{$orderLine->product->name}' are available for return.");
    //         }

    //         // Calculate refund amounts
    //         $unitPrice = (float) $orderLine->unit_price;
    //         $quantity = (int) $itemData['quantity'];
    //         $subtotal = $unitPrice * $quantity;
    //         $tax = $orderLine->gst_rate ? ($subtotal * $orderLine->gst_rate / 100) : 0;

    //         $returnItems[] = [
    //             'order_line_id' => $orderLine->id,
    //             'product_id' => $orderLine->product_id,
    //             'quantity' => $quantity,
    //             'unit_price' => $unitPrice,
    //             'subtotal' => $subtotal,
    //             'tax' => $tax,
    //             'reason' => $itemData['reason'],
    //         ];

    //         $refundSubtotal += $subtotal;
    //         $refundTax += $tax;
    //     }

    //     // Calculate shipping refund proportionally
    //     if ((float) $order->shipping_charge > 0) {
    //         $orderSubtotal = (float) $order->subtotal;
    //         $returnedProportion = $refundSubtotal / $orderSubtotal;
    //         $refundShipping = (float) $order->shipping_charge * $returnedProportion;
    //     }

    //     $totalRefund = $refundSubtotal + $refundTax + $refundShipping;

    //     // Create return request
    //     return DB::transaction(function () use ($order, $userId, $returnItems, $refundSubtotal, $refundTax, $refundShipping, $totalRefund, $data) {
    //         $returnOrder = OrderReturn::create([
    //             'order_id' => $order->id,
    //             'user_id' => $userId,
    //             'items' => $returnItems,
    //             'status' => OrderReturn::STATUS_PENDING,
    //             'reason' => $data['return_reason'] ?? null,
    //             'refund_subtotal' => round($refundSubtotal, 2),
    //             'refund_tax' => round($refundTax, 2),
    //             'refund_shipping' => round($refundShipping, 2),
    //             'total_refund_amount' => round($totalRefund, 2),
    //             'total_cv_reversed' => $this->calculateCvReversal($order, $returnItems),
    //         ]);

    //         // Update order lines
    //         foreach ($returnItems as $item) {
    //             $orderLine = OrderLine::find($item['order_line_id']);
    //             $orderLine->update([
    //                 'returned_quantity' => $orderLine->returned_quantity + $item['quantity'],
    //                 'return_status' => 'pending',
    //             ]);
    //         }

    //         // Update order status
    //         $order->update([
    //             'return_status' => 'pending',
    //         ]);

    //         // Create admin notification
    //         $this->createReturnNotification($returnOrder, 'pending');

    //         Log::info('Return request initiated', [
    //             'return_id' => $returnOrder->id,
    //             'order_reference' => $order->order_reference,
    //             'user_id' => $userId,
    //             'total_refund' => $totalRefund,
    //         ]);

    //         return [
    //             'success' => true,
    //             'return_id' => $returnOrder->id,
    //             'status' => 'pending',
    //             'message' => 'Return request submitted successfully. Admin will review and notify you.',
    //             'refund_details' => [
    //                 'subtotal' => round($refundSubtotal, 2),
    //                 'tax' => round($refundTax, 2),
    //                 'shipping' => round($refundShipping, 2),
    //                 'total' => round($totalRefund, 2),
    //             ],
    //             'items' => $returnItems,
    //         ];
    //     });
    // }
    public function initiateReturn(
        int $userId,
        array $data
    ): array {
        /*
     * Validate processed data.
     */
        $validator = Validator::make($data, [
            'order_reference' => [
                'required',
                'string',
                'exists:orders,order_reference',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.order_line_id' => [
                'required',
                'integer',
                'exists:order_lines,id',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
            'items.*.reason' => [
                'nullable',
                'string',
                'max:500',
            ],
            'items.*.image_paths' => [
                'nullable',
                'array',
                'max:5',
            ],
            'items.*.image_paths.*' => [
                'string',
            ],
            'return_reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'general_image_paths' => [
                'nullable',
                'array',
                'max:10',
            ],
            'general_image_paths.*' => [
                'string',
            ],
        ]);

        if ($validator->fails()) {
            throw new Exception(
                $validator->errors()->first()
            );
        }

        /*
     * Get order belonging to authenticated user with lines.
     */
        $order = Order::where(
            'order_reference',
            $data['order_reference']
        )
            ->where('user_id', $userId)
            ->with([
                'lines.product',
            ])
            ->first();

        if (!$order) {
            throw new Exception(
                'Order not found or does not belong to this user.'
            );
        }

        /*
     * Check if order has any delivered items.
     */
        $deliveredItemsCount = $order->lines->filter(function ($line) {
            return $line->delivery_status === 'delivered';
        })->count();

        if ($deliveredItemsCount === 0) {
            throw new Exception(
                'No items in this order have been delivered yet.'
            );
        }

        /*
     * Check if order is returnable (within 30 days from first delivery).
     */
        $firstDeliveredAt = $order->lines
            ->where('delivery_status', 'delivered')
            ->min('delivered_at');

        if (!$firstDeliveredAt) {
            throw new Exception(
                'Order has not been delivered yet.'
            );
        }

        $returnWindowDays = 30; // Configurable
        // $returnDeadline = $firstDeliveredAt->addDays($returnWindowDays);
        $firstDeliveredAt = Carbon::parse($firstDeliveredAt);

        $returnDeadline = $firstDeliveredAt->copy()->addDays($returnWindowDays);

        if (now()->gt($returnDeadline)) {
            throw new Exception(
                "Return window has expired. Returns must be initiated within {$returnWindowDays} days from delivery."
            );
        }

        /*
     * Prepare return items and validate each.
     */
        $returnItems = [];
        $refundSubtotal = 0.00;
        $refundTax = 0.00;
        $refundShipping = 0.00;
        $processedOrderLines = [];
        $returnableItems = [];
        $nonReturnableItems = [];

        foreach ($data['items'] as $itemData) {
            $orderLineId = (int) $itemData['order_line_id'];
            $quantity = (int) $itemData['quantity'];

            /*
         * Prevent same order line from being submitted multiple times.
         */
            if (in_array($orderLineId, $processedOrderLines, true)) {
                throw new Exception(
                    "Order line ID {$orderLineId} was submitted more than once."
                );
            }
            $processedOrderLines[] = $orderLineId;

            /*
         * Find order line only inside this order.
         */
            $orderLine = $order->lines
                ->firstWhere('id', $orderLineId);

            if (!$orderLine) {
                throw new Exception(
                    "Invalid order line ID: {$orderLineId}"
                );
            }

            /*
         * CRITICAL CHECK: Validate delivery status at item level.
         * Only delivered items can be returned.
         */
            if ($orderLine->delivery_status !== 'delivered') {
                throw new Exception(
                    "Item '{$orderLine->product->name}' has not been delivered yet. Only delivered items can be returned."
                );
            }

            /*
         * Check if delivery was recent enough for return window.
         */
            $itemDeliveredAt = $orderLine->delivered_at;
            if ($itemDeliveredAt && now()->diffInDays($itemDeliveredAt) > 30) {
                throw new Exception(
                    "Return window for '{$orderLine->product->name}' has expired (30 days from delivery)."
                );
            }

            /*
         * Check if this order line has already been returned.
         */
            if ($orderLine->return_status === 'returned') {
                throw new Exception(
                    "Item '{$orderLine->product->name}' has already been returned."
                );
            }

            /*
         * Check if this order line has a pending or approved return.
         */
            if (in_array($orderLine->return_status, ['pending', 'approved'])) {
                throw new Exception(
                    "Item '{$orderLine->product->name}' already has a pending or approved return request."
                );
            }

            /*
         * Check if item is returnable.
         */
            if (!$orderLine->is_returnable) {
                $nonReturnableItems[] = $orderLine->product->name;
                continue; // Skip this item instead of throwing exception
            }

            /*
         * Available quantity for return.
         */
            $available = (int) $orderLine->getAvailableForReturnAttribute();

            if ($quantity > $available) {
                $productName = $orderLine->product?->name
                    ?? 'this product';

                throw new Exception(
                    "Only {$available} units of '{$productName}' are available for return. You have {$orderLine->quantity} purchased and {$orderLine->returned_quantity} already returned."
                );
            }

            /*
         * Product price calculations.
         */
            $unitPrice = (float) $orderLine->unit_price;
            $subtotal = round($unitPrice * $quantity, 2);
            $gstRate = (float) ($orderLine->gst_rate ?? 0);
            $tax = round($subtotal * $gstRate / 100, 2);

            /*
         * Image paths.
         */
            $imagePaths = $itemData['image_paths'] ?? [];
            if (!is_array($imagePaths)) {
                $imagePaths = [];
            }

            /*
         * Build return item.
         */
            $returnItem = [
                'order_line_id' => $orderLine->id,
                'product_id' => $orderLine->product_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'reason' => $itemData['reason'] ?? null,
                'image_paths' => array_values($imagePaths),
                'return_status' => 'pending',
                'product_name' => $orderLine->product?->name ?? 'Unknown Product',
            ];

            $returnItems[] = $returnItem;
            $returnableItems[] = $orderLine->product->name;

            /*
         * Add refund amounts.
         */
            $refundSubtotal += $subtotal;
            $refundTax += $tax;
        }

        /*
     * Check if any non-returnable items were requested.
     */
        if (!empty($nonReturnableItems)) {
            throw new Exception(
                "The following items are not returnable: " . implode(', ', $nonReturnableItems)
            );
        }

        /*
     * Check if any returnable items were found.
     */
        if (empty($returnItems)) {
            throw new Exception(
                'No valid returnable items found in the request.'
            );
        }

        /*
     * Round totals.
     */
        $refundSubtotal = round($refundSubtotal, 2);
        $refundTax = round($refundTax, 2);

        /*
     * Calculate proportional shipping refund.
     */
        $orderSubtotal = (float) $order->subtotal;
        $shippingCharge = (float) $order->shipping_charge;

        if ($shippingCharge > 0 && $orderSubtotal > 0) {
            $returnedProportion = min(
                $refundSubtotal / $orderSubtotal,
                1
            );
            $refundShipping = round(
                $shippingCharge * $returnedProportion,
                2
            );
        }

        /*
     * Total refund.
     */
        $totalRefund = round(
            $refundSubtotal + $refundTax + $refundShipping,
            2
        );

        /*
     * General images.
     */
        $generalImagePaths = $data['general_image_paths'] ?? [];
        if (!is_array($generalImagePaths)) {
            $generalImagePaths = [];
        }

        /*
     * CV reversal.
     */
        $totalCvReversed = $this->calculateCvReversal(
            $order,
            $returnItems
        );

        /*
     * Create return request inside transaction.
     */
        return DB::transaction(function () use (
            $order,
            $userId,
            $returnItems,
            $generalImagePaths,
            $refundSubtotal,
            $refundTax,
            $refundShipping,
            $totalRefund,
            $totalCvReversed,
            $data
        ) {
            // Create return order
            $returnOrder = OrderReturn::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'items' => $returnItems,
                'status' => OrderReturn::STATUS_PENDING,
                'reason' => $data['return_reason'] ?? null,
                'general_images' => $generalImagePaths,
                'refund_subtotal' => $refundSubtotal,
                'refund_tax' => $refundTax,
                'refund_shipping' => $refundShipping,
                'total_refund_amount' => $totalRefund,
                'total_cv_reversed' => $totalCvReversed,
            ]);

            // Update individual order lines
            foreach ($returnItems as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);

                if ($orderLine) {
                    $currentReturnedQuantity = (int) ($orderLine->returned_quantity ?? 0);
                    $newReturnedQuantity = $currentReturnedQuantity + (int) $item['quantity'];

                    $orderLine->update([
                        'returned_quantity' => $newReturnedQuantity,
                        'return_status' => 'pending',
                        'return_requested_at' => now(),
                        // 'return_quantity' => (int) $item['quantity'],
                        'return_reason' => $item['reason'] ?? null,
                    ]);
                }
            }

            // Update order-level return status
            $this->updateOrderReturnStatus($order);

            // Update order delivery status if needed
            $this->updateOrderDeliveryStatus($order);

            /*
         * Notification.
         */
            $this->createReturnNotification(
                $returnOrder,
                'pending'
            );

            /*
         * Logging.
         */
            Log::info(
                'Return request initiated',
                [
                    'return_id' => $returnOrder->id,
                    'order_reference' => $order->order_reference,
                    'user_id' => $userId,
                    'total_refund' => $totalRefund,
                    'items_returned' => count($returnItems),
                    'returnable_items' => $returnableItems,
                    'items' => array_map(function ($item) {
                        return [
                            'order_line_id' => $item['order_line_id'],
                            'product_name' => $item['product_name'] ?? 'Unknown',
                            'quantity' => $item['quantity'],
                            'status' => 'pending'
                        ];
                    }, $returnItems),
                ]
            );

            /*
         * Return API response.
         */
            return [
                'success' => true,
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'order_delivery_status' => $order->delivery_status,
                'order_return_status' => $order->return_status,
                'status' => OrderReturn::STATUS_PENDING,
                'message' => 'Return request submitted successfully. Admin will review and notify you.',
                'refund_details' => [
                    'subtotal' => $refundSubtotal,
                    'tax' => $refundTax,
                    'shipping' => $refundShipping,
                    'total' => $totalRefund,
                    'cv_reversed' => $totalCvReversed,
                ],
                'items_returned' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'],
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'quantity' => $item['quantity'],
                        'return_status' => 'pending',
                        'reason' => $item['reason'] ?? null,
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                        'tax' => $item['tax'],
                    ];
                }, $returnItems),
                'images' => [
                    'general' => $generalImagePaths,
                    'general_urls' => $this->getImageUrls($generalImagePaths),
                    'items' => array_map(
                        function ($item) {
                            return [
                                'order_line_id' => $item['order_line_id'],
                                'product_name' => $item['product_name'] ?? 'Unknown',
                                'image_urls' => $this->getImageUrls(
                                    $item['image_paths'] ?? []
                                ),
                            ];
                        },
                        $returnItems
                    ),
                ],
            ];
        });
    }
    /**
     * Update the order's delivery status based on all its lines.
     */
    private function updateOrderDeliveryStatus(Order $order): void
    {
        $lines = $order->lines;
        $totalLines = $lines->count();

        if ($totalLines === 0) {
            $order->update(['delivery_status' => 'pending']);
            return;
        }

        $statusCounts = [
            'pending' => 0,
            'shipped' => 0,
            'delivered' => 0,
            'cancelled' => 0,
        ];

        foreach ($lines as $line) {
            $status = $line->delivery_status ?? 'pending';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        // Determine overall order delivery status
        $deliveryStatus = 'pending';

        // If all items are delivered
        if ($statusCounts['delivered'] === $totalLines) {
            $deliveryStatus = 'delivered';
        }
        // If some items are delivered
        elseif ($statusCounts['delivered'] > 0) {
            $deliveryStatus = 'partial_delivered';
        }
        // If all items are shipped
        elseif ($statusCounts['shipped'] === $totalLines) {
            $deliveryStatus = 'shipped';
        }
        // If some items are shipped
        elseif ($statusCounts['shipped'] > 0) {
            $deliveryStatus = 'processing'; // Mixed pending and shipped
        }

        $order->update(['delivery_status' => $deliveryStatus]);
    }

    /**
     * Update the order's return status based on all its lines.
     */
    private function updateOrderReturnStatus(Order $order): void
    {
        $lines = $order->lines;
        $totalLines = $lines->count();
        $deliveredLines = $lines->where('delivery_status', 'delivered')->count();

        if ($totalLines === 0 || $deliveredLines === 0) {
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

        foreach ($lines as $line) {
            // Only consider delivered items for return status
            if ($line->delivery_status !== 'delivered') {
                continue;
            }

            $status = $line->return_status ?? 'none';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        $deliveredCount = array_sum($statusCounts);

        // Determine overall order return status
        $returnStatus = 'none';

        // If all delivered items are returned
        if ($statusCounts['returned'] === $deliveredCount && $deliveredCount > 0) {
            $returnStatus = 'fully_returned';
        }
        // If some delivered items are returned
        elseif ($statusCounts['returned'] > 0) {
            // Check if any other statuses exist among delivered items
            if ($statusCounts['pending'] > 0) {
                $returnStatus = 'partial_pending';
            } elseif ($statusCounts['approved'] > 0) {
                $returnStatus = 'partial_approved';
            } elseif ($statusCounts['rejected'] > 0) {
                $returnStatus = 'partial_rejected';
            } else {
                $returnStatus = 'partial_returned';
            }
        }
        // If no items are returned but some are pending/approved/rejected
        elseif ($statusCounts['pending'] > 0) {
            $returnStatus = 'pending';
        } elseif ($statusCounts['approved'] > 0) {
            $returnStatus = 'approved';
        } elseif ($statusCounts['rejected'] > 0) {
            $returnStatus = 'rejected';
        }

        $order->update(['return_status' => $returnStatus]);
    }

    /**
     * Convert stored image paths into public URLs.
     */
    private function getImageUrls(array $paths): array
    {
        return array_values(
            array_map(
                function ($path) {

                    if (empty($path)) {
                        return null;
                    }

                    /*
                     * Already a URL.
                     */
                    if (
                        str_starts_with(
                            $path,
                            'http://'
                        ) ||
                        str_starts_with(
                            $path,
                            'https://'
                        )
                    ) {
                        return $path;
                    }

                    return asset(
                        'storage/' . ltrim($path, '/')
                    );
                },
                $paths
            )
        );
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
    // public function approveReturn(int $returnId, int $adminId, ?string $adminNotes = null): array
    // {
    //     $returnOrder = OrderReturn::with(['order', 'user'])
    //         ->findOrFail($returnId);

    //     if (!$returnOrder->canApprove()) {
    //         throw new Exception('This return request cannot be approved (already processed).');
    //     }

    //     return DB::transaction(function () use ($returnOrder, $adminId, $adminNotes) {
    //         $returnOrder->update([
    //             'status' => OrderReturn::STATUS_APPROVED,
    //             'admin_id' => $adminId,
    //             'admin_notes' => $adminNotes,
    //             'approved_at' => now(),
    //         ]);

    //         // Update individual order lines
    //         foreach ($returnOrder->items as $item) {
    //             $orderLine = OrderLine::find($item['order_line_id']);
    //             if ($orderLine) {
    //                 // Only update if it's pending
    //                 if ($orderLine->return_status === 'pending') {
    //                     $orderLine->update([
    //                         'return_status' => 'approved',
    //                         // Keep return_at as is (set during initiation)
    //                     ]);
    //                 }
    //             }
    //         }

    //         // Update order-level status
    //         $returnOrder->order->update([
    //             'return_status' => 'partial_approved',
    //         ]);

    //         // Update order lines status
    //         foreach ($returnOrder->items as $item) {
    //             $orderLine = OrderLine::find($item['order_line_id']);
    //             if ($orderLine) {
    //                 $orderLine->update(['return_status' => 'approved']);
    //             }
    //         }

    //         // Create notification for admin
    //         $this->createReturnNotification($returnOrder, 'approved');

    //         // Send notification to user
    //         $this->sendUserNotification($returnOrder, 'approved');

    //         Log::info('Return approved', [
    //             'return_id' => $returnOrder->id,
    //             'admin_id' => $adminId,
    //             'refund_amount' => $returnOrder->total_refund_amount,
    //         ]);

    //         return [
    //             'success' => true,
    //             'message' => 'Return request approved successfully.',
    //             'return_id' => $returnOrder->id,
    //             'status' => 'approved',
    //             'refund_amount' => (float) $returnOrder->total_refund_amount,
    //             'admin_notes' => $adminNotes,
    //         ];
    //     });
    // }
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

            // Update individual order lines
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine && $orderLine->return_status === 'pending') {
                    // Verify item is still delivered
                    if ($orderLine->delivery_status !== 'delivered') {
                        throw new Exception(
                            "Cannot approve return for '{$orderLine->product->name}' - item is not delivered."
                        );
                    }

                    $orderLine->update([
                        'return_status' => 'approved',
                        'return_approved_at' => now(),
                    ]);
                }
            }

            // Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);

            // Create notifications
            $this->createReturnNotification($returnOrder, 'approved');
            $this->sendUserNotification($returnOrder, 'approved');

            Log::info('Return approved', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'refund_amount' => $returnOrder->total_refund_amount,
                'items' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'],
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'status' => 'approved'
                    ];
                }, $returnOrder->items),
            ]);

            return [
                'success' => true,
                'message' => 'Return request approved successfully.',
                'return_id' => $returnOrder->id,
                'order_return_status' => $returnOrder->order->return_status,
                'status' => 'approved',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'admin_notes' => $adminNotes,
                'items' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'],
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'return_status' => 'approved'
                    ];
                }, $returnOrder->items),
            ];
        });
    }

    /**
     * Admin: Reject return request
     */
    // public function rejectReturn(int $returnId, int $adminId, string $rejectionReason): array
    // {
    //     $returnOrder = OrderReturn::with(['order', 'user'])
    //         ->findOrFail($returnId);

    //     if (!$returnOrder->canReject()) {
    //         throw new Exception('This return request cannot be rejected (already processed).');
    //     }

    //     return DB::transaction(function () use ($returnOrder, $adminId, $rejectionReason) {
    //         $returnOrder->update([
    //             'status' => OrderReturn::STATUS_REJECTED,
    //             'admin_id' => $adminId,
    //             'rejection_reason' => $rejectionReason,
    //         ]);

    //         // Update individual order lines
    //         foreach ($returnOrder->items as $item) {
    //             $orderLine = OrderLine::find($item['order_line_id']);
    //             if ($orderLine) {
    //                 // Only update if it's pending
    //                 if ($orderLine->return_status === 'pending') {
    //                     $orderLine->update([
    //                         'return_status' => 'rejected',
    //                         // Optionally clear return_at or keep it
    //                         'return_at' => null, // Or keep the timestamp
    //                     ]);
    //                 }
    //             }
    //         }

    //         // Update order-level status
    //         $returnOrder->order->update([
    //             'return_status' => 'rejected',
    //         ]);

    //         // Update order lines status
    //         foreach ($returnOrder->items as $item) {
    //             $orderLine = OrderLine::find($item['order_line_id']);
    //             if ($orderLine) {
    //                 $orderLine->update(['return_status' => 'rejected']);
    //             }
    //         }

    //         // Create notification for admin
    //         $this->createReturnNotification($returnOrder, 'rejected');

    //         // Send notification to user
    //         $this->sendUserNotification($returnOrder, 'rejected');

    //         Log::info('Return rejected', [
    //             'return_id' => $returnOrder->id,
    //             'admin_id' => $adminId,
    //             'reason' => $rejectionReason,
    //         ]);

    //         return [
    //             'success' => true,
    //             'message' => 'Return request rejected.',
    //             'return_id' => $returnOrder->id,
    //             'status' => 'rejected',
    //             'rejection_reason' => $rejectionReason,
    //         ];
    //     });
    // }
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
                'rejected_at' => now(),
            ]);

            // Update individual order lines
            foreach ($returnOrder->items as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine && $orderLine->return_status === 'pending') {
                    // Revert the returned quantity
                    $currentReturnedQuantity = (int) ($orderLine->returned_quantity ?? 0);
                    $returnQuantity = (int) ($item['quantity'] ?? 0);
                    $newReturnedQuantity = max(0, $currentReturnedQuantity - $returnQuantity);

                    $orderLine->update([
                        'return_status' => 'rejected',
                        'return_rejected_at' => now(),
                        'return_rejection_reason' => $rejectionReason,
                        'return_requested_at' => null,
                        // 'return_quantity' => 0,
                        'returned_quantity' => $newReturnedQuantity, // Revert the quantity
                    ]);
                }
            }

            // Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);

            // Create notifications
            $this->createReturnNotification($returnOrder, 'rejected');
            $this->sendUserNotification($returnOrder, 'rejected');

            Log::info('Return rejected', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'reason' => $rejectionReason,
                'items' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'],
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'status' => 'rejected'
                    ];
                }, $returnOrder->items),
            ]);

            return [
                'success' => true,
                'message' => 'Return request rejected.',
                'return_id' => $returnOrder->id,
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
     * Admin: Mark return as received and process refund
     */
    // public function markReturnReceived(int $returnId): array
    // {
    //     $returnOrder = OrderReturn::with([
    //         'order',
    //         'user',
    //         'order.lines', // Load order lines for checking
    //     ])->findOrFail($returnId);

    //     if (!$returnOrder->canMarkReceived()) {
    //         throw new Exception(
    //             'Only approved returns can be marked as received.'
    //         );
    //     }

    //     return DB::transaction(function () use ($returnOrder) {
    //         /*
    //      * 1. Mark return as received
    //      */
    //         $returnOrder->update([
    //             'status' => OrderReturn::STATUS_RECEIVED,
    //             'received_at' => now(),
    //         ]);

    //         $this->createReturnNotification(
    //             $returnOrder,
    //             'received'
    //         );

    //         /*
    //      * 2. Process Razorpay refund
    //      *
    //      * This method MUST throw an exception if Razorpay
    //      * does not create the refund.
    //      */
    //         $refundResponse = $this->processRefund($returnOrder);

    //         /*
    //      * 3. Verify actual Razorpay refund ID
    //      */
    //         if (
    //             !is_array($refundResponse) ||
    //             empty($refundResponse['refund_id'])
    //         ) {
    //             throw new Exception(
    //                 'Refund failed. Razorpay refund ID was not returned.'
    //             );
    //         }

    //         /*
    //      * 4. Refresh model so refund_transaction_id is available
    //      */
    //         $returnOrder->refresh();

    //         /*
    //      * 5. ONLY NOW mark return as completed
    //      */
    //         $returnOrder->update([
    //             'status' => OrderReturn::STATUS_COMPLETED,
    //             'completed_at' => now(),
    //         ]);

    //         /*
    //      * 6. Update individual order lines to 'returned' status
    //      */
    //         foreach ($returnOrder->items ?? [] as $item) {
    //             $orderLineId = is_array($item)
    //                 ? ($item['order_line_id'] ?? null)
    //                 : ($item->order_line_id ?? null);

    //             if (!$orderLineId) {
    //                 continue;
    //             }

    //             $orderLine = OrderLine::find($orderLineId);
    //             if ($orderLine && $orderLine->return_status === 'approved') {
    //                 $orderLine->update([
    //                     'return_status' => 'returned',
    //                 ]);
    //             }
    //         }

    //         /*
    //      * 7. Update order-level return status based on all items
    //      */
    //         if ($returnOrder->order) {
    //             $order = $returnOrder->order;

    //             // Count items with different return statuses
    //             $returnStatusCounts = [
    //                 'pending' => 0,
    //                 'approved' => 0,
    //                 'rejected' => 0,
    //                 'returned' => 0,
    //                 'none' => 0,
    //             ];

    //             foreach ($order->lines as $line) {
    //                 $status = $line->return_status ?? 'none';
    //                 if (isset($returnStatusCounts[$status])) {
    //                     $returnStatusCounts[$status]++;
    //                 }
    //             }

    //             // Determine overall order return status
    //             $orderReturnStatus = 'none';

    //             if ($returnStatusCounts['returned'] > 0) {
    //                 // Some items are returned
    //                 if ($returnStatusCounts['pending'] > 0) {
    //                     $orderReturnStatus = 'partial_returned_pending';
    //                 } elseif ($returnStatusCounts['approved'] > 0) {
    //                     $orderReturnStatus = 'partial_returned_approved';
    //                 } elseif ($returnStatusCounts['rejected'] > 0) {
    //                     $orderReturnStatus = 'partial_returned_rejected';
    //                 } elseif ($returnStatusCounts['returned'] === $order->lines->count()) {
    //                     $orderReturnStatus = 'fully_returned';
    //                 } else {
    //                     $orderReturnStatus = 'partially_returned';
    //                 }
    //             } elseif ($returnStatusCounts['approved'] > 0) {
    //                 $orderReturnStatus = 'partial_approved';
    //             } elseif ($returnStatusCounts['pending'] > 0) {
    //                 $orderReturnStatus = 'pending';
    //             } elseif ($returnStatusCounts['rejected'] > 0) {
    //                 $orderReturnStatus = 'rejected';
    //             }

    //             $order->update([
    //                 'return_status' => $orderReturnStatus,
    //             ]);
    //         }

    //         /*
    //      * 8. Completed notification
    //      */
    //         $this->createReturnNotification(
    //             $returnOrder,
    //             'completed'
    //         );

    //         /*
    //      * 9. Final logging
    //      */
    //         Log::info(
    //             'Return marked as received and Razorpay refund completed',
    //             [
    //                 'return_id' => $returnOrder->id,
    //                 'order_id' => $returnOrder->order_id,
    //                 'payment_id' => $returnOrder->order->gateway_transaction_id ?? null,
    //                 'refund_amount' => $returnOrder->total_refund_amount,
    //                 'refund_transaction_id' => $returnOrder->refund_transaction_id,
    //                 'refund_status' => $returnOrder->refund_status,
    //                 'items_returned' => count($returnOrder->items),
    //             ]
    //         );

    //         /*
    //      * 10. Return API response with per-item details
    //      */
    //         return [
    //             'success' => true,
    //             'message' => 'Return marked as received and refund processed successfully.',
    //             'return_id' => $returnOrder->id,
    //             'status' => OrderReturn::STATUS_COMPLETED,
    //             'refund_amount' => (float) $returnOrder->total_refund_amount,
    //             'refund_transaction_id' => $returnOrder->refund_transaction_id,
    //             'refund_status' => $returnOrder->refund_status,
    //             'items' => array_map(function ($item) {
    //                 return [
    //                     'order_line_id' => $item['order_line_id'] ?? null,
    //                     'product_id' => $item['product_id'] ?? null,
    //                     'quantity' => $item['quantity'] ?? 0,
    //                     'return_status' => 'returned',
    //                     'return_at' => now()->toDateTimeString(),
    //                 ];
    //             }, $returnOrder->items ?? []),
    //         ];
    //     });
    // }

    public function markReturnReceived(int $returnId): array
    {
        $returnOrder = OrderReturn::with([
            'order',
            'user',
            'order.lines',
        ])->findOrFail($returnId);

        if (!$returnOrder->canMarkReceived()) {
            throw new Exception(
                'Only approved returns can be marked as received.'
            );
        }

        return DB::transaction(function () use ($returnOrder) {
            /*
         * 1. Mark return as received
         */
            $returnOrder->update([
                'status' => OrderReturn::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            $this->createReturnNotification($returnOrder, 'received');

            /*
         * 2. Process Razorpay refund
         */
            $refundResponse = $this->processRefund($returnOrder);

            if (!is_array($refundResponse) || empty($refundResponse['refund_id'])) {
                throw new Exception(
                    'Refund failed. Razorpay refund ID was not returned.'
                );
            }

            $returnOrder->refresh();

            /*
         * 3. Mark return as completed
         */
            $returnOrder->update([
                'status' => OrderReturn::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            /*
         * 4. Update individual order lines to 'returned' status
         */
            foreach ($returnOrder->items ?? [] as $item) {
                $orderLineId = is_array($item)
                    ? ($item['order_line_id'] ?? null)
                    : ($item->order_line_id ?? null);

                if (!$orderLineId) {
                    continue;
                }

                $orderLine = OrderLine::find($orderLineId);
                if ($orderLine && $orderLine->return_status === 'approved') {
                    // Verify item is still delivered
                    if ($orderLine->delivery_status !== 'delivered') {
                        throw new Exception(
                            "Cannot complete return - item '{$orderLine->product->name}' is not delivered."
                        );
                    }

                    $orderLine->update([
                        'return_status' => 'returned',
                        'return_completed_at' => now(),
                    ]);
                }
            }

            /*
         * 5. Update order-level return status
         */
            $this->updateOrderReturnStatus($returnOrder->order);

            /*
         * 6. Completed notification
         */
            $this->createReturnNotification($returnOrder, 'completed');

            /*
         * 7. Final logging
         */
            Log::info(
                'Return marked as received and Razorpay refund completed',
                [
                    'return_id' => $returnOrder->id,
                    'order_id' => $returnOrder->order_id,
                    'payment_id' => $returnOrder->order->gateway_transaction_id ?? null,
                    'refund_amount' => $returnOrder->total_refund_amount,
                    'refund_transaction_id' => $returnOrder->refund_transaction_id,
                    'items' => array_map(function ($item) {
                        return [
                            'order_line_id' => $item['order_line_id'],
                            'product_name' => $item['product_name'] ?? 'Unknown',
                            'status' => 'returned'
                        ];
                    }, $returnOrder->items),
                ]
            );

            /*
         * 8. Return API response
         */
            return [
                'success' => true,
                'message' => 'Return marked as received and refund processed successfully.',
                'return_id' => $returnOrder->id,
                'order_return_status' => $returnOrder->order->return_status,
                'status' => OrderReturn::STATUS_COMPLETED,
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'refund_transaction_id' => $returnOrder->refund_transaction_id,
                'refund_status' => $returnOrder->refund_status,
                'items' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'quantity' => $item['quantity'] ?? 0,
                        'return_status' => 'returned',
                        'return_completed_at' => now()->toDateTimeString(),
                    ];
                }, $returnOrder->items ?? []),
            ];
        });
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
    // protected function processRefund(OrderReturn $returnOrder): void
    // {
    //     $gateway = $returnOrder->order->payment_gateway ?? 'razorpay';
    //     $refundAmount = (float) $returnOrder->total_refund_amount;

    //     if ($refundAmount <= 0) {
    //         Log::warning('Refund amount is zero or negative', [
    //             'return_id' => $returnOrder->id,
    //         ]);
    //         return;
    //     }

    //     if ($gateway === 'razorpay' && $returnOrder->order->gateway_transaction_id) {
    //         try {
    //             $this->razorpayService->refundPayment(
    //                 $returnOrder->order->gateway_transaction_id,
    //                 $refundAmount
    //             );
    //         } catch (\Exception $e) {
    //             Log::error('Refund failed for return', [
    //                 'return_id' => $returnOrder->id,
    //                 'error' => $e->getMessage(),
    //             ]);
    //             throw new Exception('Failed to process refund: ' . $e->getMessage());
    //         }
    //     }

    //     $returnOrder->update([
    //         'refund_processed_at' => now(),
    //     ]);

    //     Log::info('Refund processed for return', [
    //         'return_id' => $returnOrder->id,
    //         'amount' => $refundAmount,
    //     ]);
    // }
    // protected function processRefund(OrderReturn $returnOrder): void
    // {
    //     $gateway = $returnOrder->order->payment_gateway ?? 'razorpay';
    //     $refundAmount = (float) $returnOrder->total_refund_amount;

    //     if ($refundAmount <= 0) {
    //         Log::warning('Refund amount is zero or negative', [
    //             'return_id' => $returnOrder->id,
    //         ]);
    //         return;
    //     }

    //     if ($gateway === 'razorpay' && $returnOrder->order->gateway_transaction_id) {
    //         try {
    //             // Process the refund through Razorpay
    //             $refundResponse = $this->razorpayService->refundPayment(
    //                 $returnOrder->order->gateway_transaction_id,
    //                 $refundAmount
    //             );

    //             // Store refund transaction ID
    //             if (isset($refundResponse['refund_id'])) {
    //                 $returnOrder->update([
    //                     'refund_transaction_id' => $refundResponse['refund_id'],
    //                     'refund_status' => 'processing',
    //                 ]);
    //             }

    //             Log::info('Refund processed successfully via Razorpay', [
    //                 'return_id' => $returnOrder->id,
    //                 'refund_id' => $refundResponse['refund_id'] ?? null,
    //                 'amount' => $refundAmount,
    //             ]);
    //         } catch (\Exception $e) {
    //             Log::error('Refund failed for return', [
    //                 'return_id' => $returnOrder->id,
    //                 'payment_id' => $returnOrder->order->gateway_transaction_id,
    //                 'error' => $e->getMessage(),
    //             ]);
    //             throw new Exception('Failed to process refund: ' . $e->getMessage());
    //         }
    //     } else {
    //         Log::warning('Refund not processed - invalid gateway or missing transaction ID', [
    //             'return_id' => $returnOrder->id,
    //             'gateway' => $gateway,
    //             'transaction_id' => $returnOrder->order->gateway_transaction_id,
    //         ]);
    //     }

    //     $returnOrder->update([
    //         'refund_processed_at' => now(),
    //     ]);

    //     Log::info('Refund record updated for return', [
    //         'return_id' => $returnOrder->id,
    //         'amount' => $refundAmount,
    //         'gateway' => $gateway,
    //     ]);
    // }

    protected function processRefund(OrderReturn $returnOrder): array
    {
        $order = $returnOrder->order;

        if (!$order) {
            throw new Exception(
                'Order not found for this return.'
            );
        }

        $gateway = $order->payment_gateway ?? 'razorpay';

        $paymentId = $order->gateway_transaction_id;

        $refundAmount = (float) $returnOrder->total_refund_amount;

        /*
     * Validate refund amount
     */
        if ($refundAmount <= 0) {
            throw new Exception(
                'Refund amount must be greater than zero.'
            );
        }

        /*
     * Validate gateway
     */
        if ($gateway !== 'razorpay') {
            throw new Exception(
                'Refund is not supported for payment gateway: ' . $gateway
            );
        }

        /*
     * Validate Razorpay payment ID
     */
        if (empty($paymentId)) {
            throw new Exception(
                'Razorpay payment ID is missing for this order.'
            );
        }

        try {

            Log::info('Starting Razorpay refund', [
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'refund_amount' => $refundAmount,
                'amount_in_paise' => (int) round($refundAmount * 100),
            ]);

            /*
         * Call Razorpay
         */
            $refundResponse = $this->razorpayService->refundPayment(
                $paymentId,
                $refundAmount
            );

            /*
         * Razorpay MUST return a refund ID
         */
            if (
                !is_array($refundResponse) ||
                empty($refundResponse['refund_id'])
            ) {
                throw new Exception(
                    'Razorpay refund failed. No refund ID was returned.'
                );
            }

            /*
         * Save actual Razorpay refund details
         */
            $returnOrder->update([
                'refund_transaction_id' => $refundResponse['refund_id'],
                'refund_status' => $refundResponse['status'] ?? 'processing',
                'refund_processed_at' => now(),
            ]);

            Log::info('Refund successfully processed via Razorpay', [
                'return_id' => $returnOrder->id,
                'payment_id' => $paymentId,
                'refund_id' => $refundResponse['refund_id'],
                'refund_status' => $refundResponse['status'] ?? null,
                'amount' => $refundAmount,
            ]);

            return $refundResponse;
        } catch (\Throwable $e) {

            Log::error('Refund failed for return', [
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage(),
            ]);

            throw new Exception(
                'Failed to process refund: ' . $e->getMessage(),
                0,
                $e
            );
        }
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