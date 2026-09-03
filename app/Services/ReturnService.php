<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\AdminNotification;
use App\Models\CommissionApiEvent;
use App\Services\PaymentGateway\RazorpayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Exception;
use Carbon\Carbon;
use App\Services\Commission\CommissionServiceInterface;

class ReturnService
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
            $returnWindow = setting('return_window_days', 30);
            return [
                'eligible' => false,
                'message' => "Return window has expired. Returns must be initiated within {$returnWindow} days of delivery. ({$daysPassed} days passed)",
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
    // public function initiateReturn(
    //     int $userId,
    //     array $data
    //     ): array {
    //     /*
    //      * Validate processed data.
    //      */
    //       $validator = Validator::make($data, [
    //         'order_reference' => [
    //             'required',
    //             'string',
    //             'exists:orders,order_reference',
    //         ],
    //         'items' => [
    //             'required',
    //             'array',
    //             'min:1',
    //         ],
    //         'items.*.order_line_id' => [
    //             'required',
    //             'integer',
    //             'exists:order_lines,id',
    //         ],
    //         'items.*.quantity' => [
    //             'required',
    //             'integer',
    //             'min:1',
    //         ],
    //         'items.*.reason' => [
    //             'nullable',
    //             'string',
    //             'max:500',
    //         ],
    //         'items.*.image_paths' => [
    //             'nullable',
    //             'array',
    //             'max:5',
    //         ],
    //         'items.*.image_paths.*' => [
    //             'string',
    //         ],
    //         'return_reason' => [
    //             'nullable',
    //             'string',
    //             'max:1000',
    //         ],
    //         'general_image_paths' => [
    //             'nullable',
    //             'array',
    //             'max:10',
    //         ],
    //         'general_image_paths.*' => [
    //             'string',
    //         ],
    //        ]);

    //     if ($validator->fails()) {
    //         throw new Exception(
    //             $validator->errors()->first()
    //         );
    //     }

    //     /*
    //      * Get order belonging to authenticated user with lines.
    //      */
    //     $order = Order::where(
    //         'order_reference',
    //         $data['order_reference']
    //     )
    //         ->where('user_id', $userId)
    //         ->with([
    //             'lines.product',
    //         ])
    //         ->first();

    //     if (!$order) {
    //         throw new Exception(
    //             'Order not found or does not belong to this user.'
    //         );
    //     }

    //     /*
    //      * Check if order has any delivered items.
    //      */
    //     $deliveredItemsCount = $order->lines->filter(function ($line) {
    //         return $line->delivery_status === 'delivered';
    //     })->count();

    //     if ($deliveredItemsCount == 0) {
    //         throw new Exception(
    //             'No items in this order have been delivered yet.'
    //         );
    //     }

    //     /*
    //      * Check if order is returnable (within 30 days from first delivery).
    //      */
    //     $firstDeliveredAt = $order->lines
    //         ->where('delivery_status', 'delivered')
    //         ->min('delivered_at');

    //     if (!$firstDeliveredAt) {
    //         throw new Exception(
    //             'Order has not been delivered yet.'
    //         );
    //     }

    //     $returnWindowDays = setting('return_window_days', 30);
    //     $firstDeliveredAt = Carbon::parse($firstDeliveredAt);
    //     $returnDeadline = $firstDeliveredAt->copy()->addDays($returnWindowDays);

    //     if (now()->gt($returnDeadline)) {
    //         throw new Exception(
    //             "Return window has expired. Returns must be initiated within {$returnWindowDays} days from delivery."
    //         );
    //     }

    //     /*
    //      * Prepare return items and validate each.
    //      */
    //     $returnItems = [];
    //     $refundSubtotal = 0.00;
    //     $refundTax = 0.00;
    //     $refundTotal = 0.00; // This will be the line_total
    //     $processedOrderLines = [];
    //     $returnableItems = [];
    //     $nonReturnableItems = [];

    //     foreach ($data['items'] as $itemData) {
    //         $orderLineId = (int) $itemData['order_line_id'];
    //         $quantity = (int) $itemData['quantity'];

    //         /*
    //          * Prevent same order line from being submitted multiple times.
    //          */
    //         if (in_array($orderLineId, $processedOrderLines, true)) {
    //             throw new Exception(
    //                 "Order line ID {$orderLineId} was submitted more than once."
    //             );
    //         }
    //         $processedOrderLines[] = $orderLineId;

    //         /*
    //          * Find order line only inside this order.
    //          */
    //         $orderLine = $order->lines
    //             ->firstWhere('id', $orderLineId);

    //         if (!$orderLine) {
    //             throw new Exception(
    //                 "Invalid order line ID: {$orderLineId}"
    //             );
    //         }

    //         /*
    //          * CRITICAL CHECK: Validate delivery status at item level.
    //          * Only delivered items can be returned.
    //          */
    //         if ($orderLine->delivery_status !== 'delivered') {
    //             throw new Exception(
    //                 "Item '{$orderLine->product->name}' has not been delivered yet. Only delivered items can be returned."
    //             );
    //         }

    //         /*
    //          * Check if delivery was recent enough for return window.
    //          */
    //         $itemDeliveredAt = $orderLine->delivered_at;
    //         if ($itemDeliveredAt && now()->diffInDays($itemDeliveredAt) > $returnWindowDays) {
    //             throw new Exception(
    //                 "Return window for '{$orderLine->product->name}' has expired ({$returnWindowDays} days from delivery)."
    //             );
    //         }

    //         /*
    //          * Check if this order line has already been returned.
    //          */
    //         if ($orderLine->return_status === 'returned') {
    //             throw new Exception(
    //                 "Item '{$orderLine->product->name}' has already been returned."
    //             );
    //         }

    //         /*
    //          * Check if this order line has a pending or approved return.
    //          */
    //         if (in_array($orderLine->return_status, ['pending', 'approved'])) {
    //             throw new Exception(
    //                 "Item '{$orderLine->product->name}' already has a pending or approved return request."
    //             );
    //         }

    //         /*
    //          * Check if item is returnable.
    //          */
    //         if (!$orderLine->is_returnable) {
    //             $nonReturnableItems[] = $orderLine->product->name;
    //             continue;
    //         }

    //         /*
    //          * Available quantity for return.
    //          */
    //         $available = (int) $orderLine->getAvailableForReturnAttribute();

    //         if ($quantity > $available) {
    //             $productName = $orderLine->product?->name
    //                 ?? 'this product';

    //             throw new Exception(
    //                 "Only {$available} units of '{$productName}' are available for return. You have {$orderLine->quantity} purchased and {$orderLine->returned_quantity} already returned."
    //             );
    //         }

    //         /*
    //          * Calculate refund amounts based on line_total.
    //          * line_total = unit_price * quantity + GST
    //          */
    //         $unitPrice = (float) $orderLine->unit_price;
    //         $gstRate = (float) ($orderLine->gst_rate ?? 0);

    //         // Calculate per unit values
    //         $perUnitTotal = $orderLine->line_total / $orderLine->quantity;
    //         $perUnitSubtotal = $unitPrice;
    //         $perUnitTax = $perUnitTotal - $perUnitSubtotal;

    //         // Calculate for the quantity being returned
    //         $subtotal = round($unitPrice * $quantity, 2);
    //         $tax = round($perUnitTax * $quantity, 2);
    //         $lineTotal = round($perUnitTotal * $quantity, 2); // This is the total refund amount for this item

    //         /*
    //          * Image paths.
    //          */
    //         $imagePaths = $itemData['image_paths'] ?? [];
    //         if (!is_array($imagePaths)) {
    //             $imagePaths = [];
    //         }

    //         /*
    //          * Build return item.
    //          */
    //         $returnItem = [
    //             'order_line_id' => $orderLine->id,
    //             'product_id' => $orderLine->product_id,
    //             'product_name' => $orderLine->product?->name ?? 'Unknown Product',
    //             'quantity' => $quantity,
    //             'unit_price' => $unitPrice,
    //             'gst_rate' => $gstRate,
    //             'subtotal' => $subtotal,
    //             'tax' => $tax,
    //             'line_total' => $lineTotal, // This is the total refund for this item
    //             'reason' => $itemData['reason'] ?? null,
    //             'image_paths' => array_values($imagePaths),
    //             'return_status' => 'pending',
    //         ];

    //         $returnItems[] = $returnItem;
    //         $returnableItems[] = $orderLine->product->name;

    //         /*
    //          * Add refund amounts.
    //          */
    //         $refundSubtotal += $subtotal;
    //         $refundTax += $tax;
    //         $refundTotal += $lineTotal;
    //     }

    //     /*
    //      * Check if any non-returnable items were requested.
    //      */
    //     if (!empty($nonReturnableItems)) {
    //         throw new Exception(
    //             "The following items are not returnable: " . implode(', ', $nonReturnableItems)
    //         );
    //     }

    //     /*
    //      * Check if any returnable items were found.
    //      */
    //     if (empty($returnItems)) {
    //         throw new Exception(
    //             'No valid returnable items found in the request.'
    //         );
    //     }

    //     /*
    //      * Round totals.
    //      */
    //     $refundSubtotal = round($refundSubtotal, 2);
    //     $refundTax = round($refundTax, 2);
    //     $refundTotal = round($refundTotal, 2);

    //     /*
    //      * Calculate proportional shipping refund.
    //      */
    //     $orderSubtotal = (float) $order->subtotal;
    //     $shippingCharge = (float) $order->shipping_charge;

    //     if ($shippingCharge > 0 && $orderSubtotal > 0) {
    //         $returnedProportion = min(
    //             $refundSubtotal / $orderSubtotal,
    //             1
    //         );
    //         $refundShipping = round(
    //             $shippingCharge * $returnedProportion,
    //             2
    //         );
    //     } else {
    //         $refundShipping = 0.00;
    //     }

    //     /*
    //      * Total refund = line_total of returned items + proportional shipping
    //      */
    //     $totalRefund = round(
    //         $refundTotal + $refundShipping,
    //         2
    //     );

    //     /*
    //      * General images.
    //      */
    //     $generalImagePaths = $data['general_image_paths'] ?? [];
    //     if (!is_array($generalImagePaths)) {
    //         $generalImagePaths = [];
    //     }

    //     /*
    //      * CV reversal.
    //      */
    //     $totalCvReversed = $this->calculateCvReversal(
    //         $order,
    //         $returnItems
    //     );

    //     /*
    //      * Create return request inside transaction.
    //      */
    //     return DB::transaction(function () use (
    //         $order,
    //         $userId,
    //         $returnItems,
    //         $generalImagePaths,
    //         $refundSubtotal,
    //         $refundTax,
    //         $refundTotal,
    //         $refundShipping,
    //         $totalRefund,
    //         $totalCvReversed,
    //         $data
    //     ) {
    //         // Create return order
    //         $returnOrder = OrderReturn::create([
    //             'order_id' => $order->id,
    //             'user_id' => $userId,
    //             'items' => $returnItems,
    //             'status' => OrderReturn::STATUS_PENDING,
    //             'reason' => $data['return_reason'] ?? null,
    //             'general_images' => $generalImagePaths,
    //             'refund_subtotal' => $refundSubtotal,
    //             'refund_tax' => $refundTax,
    //             'refund_shipping' => $refundShipping,
    //             'total_refund_amount' => $totalRefund,
    //             'total_cv_reversed' => $totalCvReversed,
    //         ]);

    //         // Update individual order lines
    //         foreach ($returnItems as $item) {
    //             $orderLine = OrderLine::find($item['order_line_id']);

    //             if ($orderLine) {
    //                 $currentReturnedQuantity = (int) ($orderLine->returned_quantity ?? 0);
    //                 $newReturnedQuantity = $currentReturnedQuantity + (int) $item['quantity'];

    //                 $orderLine->update([
    //                     'returned_quantity' => $newReturnedQuantity,
    //                     'return_status' => 'pending',
    //                     'delivery_status' => 'return_pending',
    //                     'return_requested_at' => now(),
    //                     // 'return_quantity' => (int) $item['quantity'],
    //                     'return_reason' => $item['reason'] ?? null,
    //                 ]);
    //             }
    //         }

    //         // Update order-level return status
    //         $this->updateOrderReturnStatus($order);

    //         // Update order main status
    //         $this->updateOrderMainStatus($order);

    //         /*
    //          * Notification.
    //          */
    //         $this->createReturnNotification(
    //             $returnOrder,
    //             'pending'
    //         );

    //         /*
    //          * Logging.
    //          */
    //         Log::info(
    //             'Return request initiated',
    //             [
    //                 'return_id' => $returnOrder->id,
    //                 'order_reference' => $order->order_reference,
    //                 'user_id' => $userId,
    //                 'total_refund' => $totalRefund,
    //                 'refund_breakdown' => [
    //                     'subtotal' => $refundSubtotal,
    //                     'tax' => $refundTax,
    //                     'line_total' => $refundTotal,
    //                     'shipping' => $refundShipping,
    //                 ],
    //                 'items_returned' => count($returnItems),
    //                 'items' => array_map(function ($item) {
    //                     return [
    //                         'order_line_id' => $item['order_line_id'],
    //                         'product_name' => $item['product_name'] ?? 'Unknown',
    //                         'quantity' => $item['quantity'],
    //                         'line_total' => $item['line_total'],
    //                         'status' => 'pending'
    //                     ];
    //                 }, $returnItems),
    //             ]
    //         );

    //         /*
    //          * Return API response.
    //          */
    //         return [
    //             'success' => true,
    //             'return_id' => $returnOrder->id,
    //             'order_id' => $order->id,
    //             'order_reference' => $order->order_reference,
    //             'order_status' => $order->status,
    //             'order_return_status' => $order->return_status,
    //             'status' => OrderReturn::STATUS_PENDING,
    //             'message' => 'Return request submitted successfully. Admin will review and notify you.',
    //             'refund_details' => [
    //                 'subtotal' => $refundSubtotal,
    //                 'tax' => $refundTax,
    //                 'line_total' => $refundTotal,
    //                 'shipping' => $refundShipping,
    //                 'total' => $totalRefund,
    //                 'cv_reversed' => $totalCvReversed,
    //             ],
    //             'items_returned' => array_map(function ($item) {
    //                 return [
    //                     'order_line_id' => $item['order_line_id'],
    //                     'product_id' => $item['product_id'],
    //                     'product_name' => $item['product_name'] ?? 'Unknown',
    //                     'quantity' => $item['quantity'],
    //                     'unit_price' => $item['unit_price'],
    //                     'gst_rate' => $item['gst_rate'],
    //                     'subtotal' => $item['subtotal'],
    //                     'tax' => $item['tax'],
    //                     'line_total' => $item['line_total'],
    //                     'return_status' => 'pending',
    //                     'reason' => $item['reason'] ?? null,
    //                 ];
    //             }, $returnItems),
    //             'images' => [
    //                 'general' => $generalImagePaths,
    //                 'general_urls' => $this->getImageUrls($generalImagePaths),
    //                 'items' => array_map(
    //                     function ($item) {
    //                         return [
    //                             'order_line_id' => $item['order_line_id'],
    //                             'product_name' => $item['product_name'] ?? 'Unknown',
    //                             'image_urls' => $this->getImageUrls(
    //                                 $item['image_paths'] ?? []
    //                             ),
    //                         ];
    //                     },
    //                     $returnItems
    //                 ),
    //             ],
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

        if ($deliveredItemsCount == 0) {
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

        $returnWindowDays = setting('return_window_days', 30);
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
        $refundTotal = 0.00;
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
            if ($itemDeliveredAt && now()->diffInDays($itemDeliveredAt) > $returnWindowDays) {
                throw new Exception(
                    "Return window for '{$orderLine->product->name}' has expired ({$returnWindowDays} days from delivery)."
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
                continue;
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
             * Calculate refund amounts based on line_total.
             * line_total = unit_price * quantity + GST
            */
            $unitPrice = (float) $orderLine->unit_price;
            $gstRate = (float) ($orderLine->gst_rate ?? 0);

            // Taxable value
            $subtotal = round($unitPrice * $quantity, 2);

            // GST amount from rate
            $gstPercentage = $gstRate / 100;
            $tax = round($subtotal * $gstPercentage, 2);

            // Line total = taxable + GST
            $lineTotal = round($subtotal + $tax, 2);

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
                'product_name' => $orderLine->product?->name ?? 'Unknown Product',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gst_rate' => $gstRate,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'line_total' => $lineTotal,
                'reason' => $itemData['reason'] ?? null,
                'image_paths' => array_values($imagePaths),
                'return_status' => 'pending',
            ];

            $returnItems[] = $returnItem;
            $returnableItems[] = $orderLine->product->name;

            /*
                 * Add refund amounts.
                 */
            $refundSubtotal += $subtotal;
            $refundTax += $tax;
            $refundTotal += $lineTotal;
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
        $refundTotal = round($refundTotal, 2);

        /*
             * Total refund = line_total of returned items
             */
        $totalRefund = round($refundTotal, 2);

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
            $refundTotal,
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
                        'delivery_status' => 'return_pending',
                        'return_requested_at' => now(),
                        'return_reason' => $item['reason'] ?? null,
                    ]);
                }
            }

            // Update order-level return status
            $this->updateOrderReturnStatus($order);

            // Update order main status
            $this->updateOrderMainStatus($order);

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
                    'refund_breakdown' => [
                        'subtotal' => $refundSubtotal,
                        'tax' => $refundTax,
                        'line_total' => $refundTotal,
                    ],
                    'items_returned' => count($returnItems),
                    'items' => array_map(function ($item) {
                        return [
                            'order_line_id' => $item['order_line_id'],
                            'product_name' => $item['product_name'] ?? 'Unknown',
                            'quantity' => $item['quantity'],
                            'line_total' => $item['line_total'],
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
                'order_status' => $order->status,
                'order_return_status' => $order->return_status,
                'status' => OrderReturn::STATUS_PENDING,
                'message' => 'Return request submitted successfully. Admin will review and notify you.',
                'refund_details' => [
                    'subtotal' => $refundSubtotal,
                    'tax' => $refundTax,
                    'line_total' => $refundTotal,
                    'total' => $totalRefund,
                    'cv_reversed' => $totalCvReversed,
                ],
                'items_returned' => array_map(function ($item) {
                    return [
                        'order_line_id' => $item['order_line_id'],
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'] ?? 'Unknown',
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'gst_rate' => $item['gst_rate'],
                        'subtotal' => $item['subtotal'],
                        'tax' => $item['tax'],
                        'line_total' => $item['line_total'],
                        'return_status' => 'pending',
                        'reason' => $item['reason'] ?? null,
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
    // private function updateOrderDeliveryStatus(Order $order): void
    // {
    //     $lines = $order->lines;
    //     $totalLines = $lines->count();

    //     if ($totalLines === 0) {
    //         $order->update(['delivery_status' => 'pending']);
    //         return;
    //     }

    //     $statusCounts = [
    //         'pending' => 0,
    //         'shipped' => 0,
    //         'delivered' => 0,
    //         'cancelled' => 0,
    //     ];

    //     foreach ($lines as $line) {
    //         $status = $line->delivery_status ?? 'pending';
    //         if (isset($statusCounts[$status])) {
    //             $statusCounts[$status]++;
    //         }
    //     }

    //     // Determine overall order delivery status
    //     $deliveryStatus = 'pending';

    //     // If all items are delivered
    //     if ($statusCounts['delivered'] === $totalLines) {
    //         $deliveryStatus = 'delivered';
    //     }
    //     // If some items are delivered
    //     elseif ($statusCounts['delivered'] > 0) {
    //         $deliveryStatus = 'partial_delivered';
    //     }
    //     // If all items are shipped
    //     elseif ($statusCounts['shipped'] === $totalLines) {
    //         $deliveryStatus = 'shipped';
    //     }
    //     // If some items are shipped
    //     elseif ($statusCounts['shipped'] > 0) {
    //         $deliveryStatus = 'processing'; // Mixed pending and shipped
    //     }

    //     $order->update(['delivery_status' => $deliveryStatus]);
    // }
    private function updateOrderDeliveryStatus(Order $order): void
    {
        $allLines = $order->lines;
        $totalLines = $allLines->count();

        if ($totalLines === 0) {
            $order->update(['delivery_status' => 'pending']);
            return;
        }

        // Get active lines (excluding returned and cancelled)
        $activeLines = $allLines->filter(function ($line) {
            return !in_array($line->delivery_status, ['returned', 'cancelled']);
        });

        $activeCount = $activeLines->count();

        if ($activeCount === 0) {
            $order->update(['delivery_status' => 'returned']);
            return;
        }

        // Count delivery statuses (including return_pending)
        $deliveryCounts = [
            'pending' => 0,
            'confirmed' => 0,
            'dispatched' => 0,
            'shipped' => 0,
            'delivered' => 0,
            'return_pending' => 0,
        ];

        foreach ($activeLines as $line) {
            $status = $line->delivery_status ?? 'pending';
            if (isset($deliveryCounts[$status])) {
                $deliveryCounts[$status]++;
            }
        }

        $deliveryStatus = 'pending';

        // Check if any return_pending exists
        if ($deliveryCounts['return_pending'] > 0) {
            // For delivery_status, we want to show the delivery progress
            // Return pending items should not affect delivery status negatively
            // Remove return_pending from active count for delivery determination
            $activeWithoutReturn = $activeCount - $deliveryCounts['return_pending'];

            if ($activeWithoutReturn === 0) {
                $deliveryStatus = 'pending'; // All items are pending return
            } elseif ($deliveryCounts['delivered'] === $activeWithoutReturn && $deliveryCounts['return_pending'] > 0) {
                $deliveryStatus = 'partial_delivered';
            } else {
                // Fall back to normal logic but exclude return_pending from count
                $normalActiveCount = $activeWithoutReturn;
                $deliveredCount = $deliveryCounts['delivered'];
                $shippedCount = $deliveryCounts['shipped'];
                $dispatchedCount = $deliveryCounts['dispatched'];
                $confirmedCount = $deliveryCounts['confirmed'];
                $pendingCount = $deliveryCounts['pending'];

                if ($normalActiveCount === 0) {
                    $deliveryStatus = 'pending';
                } elseif ($deliveredCount === $normalActiveCount) {
                    $deliveryStatus = 'delivered';
                } elseif ($deliveredCount > 0) {
                    $deliveryStatus = 'partial_delivered';
                } elseif ($shippedCount === $normalActiveCount) {
                    $deliveryStatus = 'shipped';
                } elseif ($shippedCount > 0) {
                    $deliveryStatus = 'partial_shipped';
                } elseif ($dispatchedCount === $normalActiveCount) {
                    $deliveryStatus = 'dispatched';
                } elseif ($dispatchedCount > 0) {
                    $deliveryStatus = 'partial_dispatched';
                } elseif ($confirmedCount === $normalActiveCount) {
                    $deliveryStatus = 'confirmed';
                } elseif ($confirmedCount > 0) {
                    $deliveryStatus = 'partial_confirmed';
                } else {
                    $deliveryStatus = 'pending';
                }
            }
        } else {
            // No return_pending, use original logic
            if ($deliveryCounts['delivered'] === $activeCount) {
                $deliveryStatus = 'delivered';
            } elseif ($deliveryCounts['delivered'] > 0) {
                $deliveryStatus = 'partial_delivered';
            } elseif ($deliveryCounts['shipped'] === $activeCount) {
                $deliveryStatus = 'shipped';
            } elseif ($deliveryCounts['shipped'] > 0) {
                $deliveryStatus = 'partial_shipped';
            } elseif ($deliveryCounts['dispatched'] === $activeCount) {
                $deliveryStatus = 'dispatched';
            } elseif ($deliveryCounts['dispatched'] > 0) {
                $deliveryStatus = 'partial_dispatched';
            } elseif ($deliveryCounts['confirmed'] === $activeCount) {
                $deliveryStatus = 'confirmed';
            } elseif ($deliveryCounts['confirmed'] > 0) {
                $deliveryStatus = 'partial_confirmed';
            }
        }

        $order->update(['delivery_status' => $deliveryStatus]);
    }

    /**
     * Update the order's return status based on all its lines.
     */
    // private function updateOrderReturnStatus(Order $order): void
    // {
    //     $lines = $order->lines;
    //     $totalLines = $lines->count();
    //     $deliveredLines = $lines->where('delivery_status', 'delivered')->count();

    //     if ($totalLines === 0 || $deliveredLines === 0) {
    //         $order->update(['return_status' => 'none']);
    //         return;
    //     }

    //     $statusCounts = [
    //         'pending' => 0,
    //         'approved' => 0,
    //         'rejected' => 0,
    //         'returned' => 0,
    //         'none' => 0,
    //     ];

    //     foreach ($lines as $line) {
    //         // Only consider delivered items for return status
    //         if ($line->delivery_status !== 'delivered') {
    //             continue;
    //         }

    //         $status = $line->return_status ?? 'none';
    //         if (isset($statusCounts[$status])) {
    //             $statusCounts[$status]++;
    //         }
    //     }

    //     $deliveredCount = array_sum($statusCounts);

    //     // Determine overall order return status
    //     $returnStatus = 'none';

    //     // If all delivered items are returned
    //     if ($statusCounts['returned'] === $deliveredCount && $deliveredCount > 0) {
    //         $returnStatus = 'fully_returned';
    //     }
    //     // If some delivered items are returned
    //     elseif ($statusCounts['returned'] > 0) {
    //         // Check if any other statuses exist among delivered items
    //         if ($statusCounts['pending'] > 0) {
    //             $returnStatus = 'partial_pending';
    //         } elseif ($statusCounts['approved'] > 0) {
    //             $returnStatus = 'partial_approved';
    //         } elseif ($statusCounts['rejected'] > 0) {
    //             $returnStatus = 'partial_rejected';
    //         } else {
    //             $returnStatus = 'partial_return';
    //         }
    //     }
    //     // If no items are returned but some are pending/approved/rejected
    //     elseif ($statusCounts['pending'] > 0) {
    //         $returnStatus = 'pending';
    //     } elseif ($statusCounts['approved'] > 0) {
    //         $returnStatus = 'approved';
    //     } elseif ($statusCounts['rejected'] > 0) {
    //         $returnStatus = 'rejected';
    //     }

    //     $order->update(['return_status' => $returnStatus]);
    // }
    private function updateOrderReturnStatus(Order $order): void
    {
        $lines = $order->lines;
        $totalLines = $lines->count();

        // Count returned items
        $returnedCount = $lines->where('delivery_status', 'returned')->count();
        $pendingReturns = $lines->where('return_status', 'pending')->count();
        $approvedReturns = $lines->where('return_status', 'approved')->count();
        $rejectedReturns = $lines->where('return_status', 'rejected')->count();

        // Count delivered items (active + returned)
        $deliveredCount = $lines->where('delivery_status', 'delivered')->count();
        $returnedDeliveredCount = $lines->where('delivery_status', 'returned')->count();
        $totalDelivered = $deliveredCount + $returnedDeliveredCount;

        if ($totalLines === 0 || $totalDelivered === 0) {
            $order->update(['return_status' => 'none']);
            return;
        }

        $returnStatus = 'none';

        // If all delivered items are returned
        if ($returnedCount === $totalDelivered && $totalDelivered > 0) {
            $returnStatus = 'fully_returned';
        }
        // If some delivered items are returned
        elseif ($returnedCount > 0) {
            if ($pendingReturns > 0) {
                $returnStatus = 'partial_pending';
            } elseif ($approvedReturns > 0) {
                $returnStatus = 'partial_approved';
            } elseif ($rejectedReturns > 0) {
                $returnStatus = 'partial_rejected';
            } else {
                $returnStatus = 'partial_returned';
            }
        }
        // If no items are returned but some are pending/approved/rejected
        elseif ($pendingReturns > 0) {
            $returnStatus = 'pending';
        } elseif ($approvedReturns > 0) {
            $returnStatus = 'approved';
        } elseif ($rejectedReturns > 0) {
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

    public function approveReturn(int $returnId, int $adminId, ?string $adminNotes = null): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->findOrFail($returnId);

        if (!$returnOrder->canApprove()) {
            throw new Exception('This return request cannot be approved (already processed).');
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

            // ========== REVERSAL TRIGGER ==========
            try {
                $order = $returnOrder->order;

                // Build payload
                $payload = [
                    'eventId' => 'evt_' . \Illuminate\Support\Str::random(24),
                    'action' => 'REVERSAL',
                    'orderReference' => $order->order_reference,
                    'reason' => $returnOrder->reason ?? 'Return approved by admin',
                    'lines' => $this->buildReversalLines($returnOrder),
                    'reversedValue' => (float) $returnOrder->total_refund_amount,
                    'originalCv' => (float) ($order->commissionable_volume ?? 0),
                    'purchaserIdentifier' => (string) $returnOrder->user_id,
                    'accountType' => $order->order_type === 'distributor' ? 'DISTRIBUTOR' : 'CUSTOMER',
                    'eventTimestamp' => now()->toIso8601String(),
                ];

                // ✅ INSERT INTO DATABASE
                $event = CommissionApiEvent::create([
                    'event_type' => 'reversal',
                    'order_id' => $order->id,
                    'payload' => json_encode($payload),
                    'status' => 'pending',
                    'retry_count' => 0,
                    'max_retries' => 5,
                ]);

                Log::info('Reversal event SAVED in database', [
                    'event_id' => $event->id,
                    'return_id' => $returnOrder->id,
                    'order_reference' => $order->order_reference,
                ]);

                // Send to Commission API
                try {
                    $reversalPayload = new \App\Services\Commission\ReversalPayload(
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

                    // Update event status after successful API call
                    $event->update(['status' => 'sent']);

                    Log::info('Reversal event posted successfully', [
                        'return_id' => $returnOrder->id,
                        'event_id' => $event->id,
                    ]);
                } catch (\Exception $e) {
                    // Update event with failure
                    $event->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'last_attempt' => now(),
                    ]);

                    Log::error('Failed to send reversal to Commission API', [
                        'return_id' => $returnOrder->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to create reversal event', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
            // ========== END REVERSAL TRIGGER ==========

            // 4. Create notifications
            $this->createReturnNotification($returnOrder, 'approved');
            $this->sendUserNotification($returnOrder, 'approved');

            // 5. Return response
            return [
                'success' => true,
                'message' => 'Return request approved successfully.',
                'return_id' => $returnOrder->id,
                'order_status' => $returnOrder->order->status,
                'order_return_status' => $returnOrder->order->return_status,
                'status' => 'approved',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'admin_notes' => $adminNotes,
            ];
        });
    }

    /**
     * Build reversal lines for Commission API
     */
    private function buildReversalLines(OrderReturn $returnOrder): array
    {
        $lines = [];
        $items = $returnOrder->items ?? [];

        foreach ($items as $item) {
            // Fetch exact order line by ID
            $orderLine = OrderLine::find($item['order_line_id']);
            if (!$orderLine) {
                continue; // or log warning
            }

            $lines[] = [
                'productIdentifier' => (string) $orderLine->product_id,
                'quantity' => (int) $item['quantity'],
                'unitPriceCharged' => number_format((float) $orderLine->unit_price, 2, '.', ''),
                'taxCategory' => 'GST', // Consider dynamic if needed
            ];
        }
        return $lines;
    }

    /**
     * Restore stock for a return
     * Handles both simple products and product variants
     */
    protected function restoreStockForReturn(OrderReturn $returnOrder): void
    {
        $items = $returnOrder->items ?? [];
        $order = $returnOrder->order;

        Log::info('Starting stock restore', [
            'return_id' => $returnOrder->id,
            'type' => $returnOrder->type,
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
                // Product with variant
                $variant = \App\Models\ProductVariant::find($variantId);
                if ($variant) {
                    $variant->stock_quantity += $quantity;
                    $variant->save();

                    Log::info('Variant stock restored', [
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
                // Simple product
                $product = \App\Models\Product::find($productId);
                if ($product) {
                    $product->stock_quantity += $quantity;
                    $product->save();

                    Log::info('Product stock restored', [
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
                ? (\App\Models\ProductVariant::find($variantId)?->stock_quantity ?? 0)
                : (\App\Models\Product::find($productId)?->stock_quantity ?? 0);

            $reason = match ($returnOrder->type) {
                'cooling_off' => 'Cooling-off withdrawal approved: ' . ($order->order_reference ?? ''),
                'buyback' => 'Buy-back approved: ' . ($order->order_reference ?? ''),
                default => 'Return approved: ' . ($order->order_reference ?? ''),
            };

            \App\Models\StockMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'available_quantity_after' => $availableAfter,
                'reason' => $reason,
                'admin_id' => auth()->id() ?? 1,
                'order_id' => $order->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update order line returned_quantity
            $orderLine->returned_quantity += $quantity;
            $orderLine->save();
        }

        Log::info('Stock restore completed', [
            'return_id' => $returnOrder->id,
            'items_restored' => count($items),
        ]);
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
                'status' => 'rejected',
                'admin_id' => $adminId,
                'rejection_reason' => $rejectionReason,
                'rejected_at' => now(),
            ]);

            // Update individual order lines
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
                        // 'return_quantity' => 0,
                        'returned_quantity' => $newReturnedQuantity,
                    ]);
                }
            }

            // Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);

            // Update order main status - IMPORTANT: revert to delivery status if no returns
            $this->updateOrderMainStatus($returnOrder->order);

            // Create notifications
            $this->createReturnNotification($returnOrder, 'rejected');
            $this->sendUserNotification($returnOrder, 'rejected');

            Log::info('Return rejected', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'reason' => $rejectionReason,
                'order_status' => $returnOrder->order->status,
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
     * Update the order's main status based on delivery and return status.
     */
    // private function updateOrderMainStatus(Order $order): void
    // {
    //     $lines = $order->lines;
    //     $totalLines = $lines->count();

    //     if ($totalLines === 0) {
    //         $order->update(['status' => 'pending']);
    //         return;
    //     }

    //     // First, determine delivery status
    //     $deliveryCounts = [
    //         'pending' => 0,
    //         'shipped' => 0,
    //         'delivered' => 0,
    //         'cancelled' => 0,
    //     ];

    //     foreach ($lines as $line) {
    //         $status = $line->delivery_status ?? 'pending';
    //         if (isset($deliveryCounts[$status])) {
    //             $deliveryCounts[$status]++;
    //         }
    //     }

    //     // Determine delivery status
    //     $deliveryStatus = 'pending';
    //     if ($deliveryCounts['delivered'] === $totalLines) {
    //         $deliveryStatus = 'delivered';
    //     } elseif ($deliveryCounts['delivered'] > 0) {
    //         $deliveryStatus = 'partial_delivered';
    //     } elseif ($deliveryCounts['shipped'] === $totalLines) {
    //         $deliveryStatus = 'shipped';
    //     } elseif ($deliveryCounts['shipped'] > 0) {
    //         $deliveryStatus = 'processing';
    //     }

    //     // Now, determine return status based on delivered items only
    //     $deliveredLines = $lines->where('delivery_status', 'delivered');
    //     $deliveredCount = $deliveredLines->count();

    //     if ($deliveredCount === 0) {
    //         // If no items delivered, just use delivery status
    //         $order->update(['status' => $deliveryStatus]);
    //         return;
    //     }

    //     $returnCounts = [
    //         'pending' => 0,
    //         'approved' => 0,
    //         'rejected' => 0,
    //         'returned' => 0,
    //         'none' => 0,
    //     ];

    //     foreach ($deliveredLines as $line) {
    //         $status = $line->return_status ?? 'none';
    //         if (isset($returnCounts[$status])) {
    //             $returnCounts[$status]++;
    //         }
    //     }

    //     // Determine final order status (combining delivery and return)
    //     $finalStatus = $deliveryStatus;

    //     // If ALL delivered items are returned
    //     if ($returnCounts['returned'] === $deliveredCount && $deliveredCount > 0) {
    //         // Check if all items are delivered and returned
    //         if ($deliveryCounts['delivered'] === $totalLines) {
    //             $finalStatus = 'returned'; // Full order returned
    //         } else {
    //             // Some items are not delivered, but all delivered ones are returned
    //             $finalStatus = 'partial_return';
    //         }
    //     }
    //     // If SOME delivered items are returned
    //     elseif ($returnCounts['returned'] > 0) {
    //         $finalStatus = 'partial_returned';
    //     }
    //     // If no delivered items are returned but some have pending/approved/rejected
    //     elseif ($returnCounts['pending'] > 0 || $returnCounts['approved'] > 0 || $returnCounts['rejected'] > 0) {
    //         // Keep the delivery status, but we could add a prefix if needed
    //         // For now, keep delivery status as is
    //     }

    //     $order->update(['status' => $finalStatus]);
    // }
    private function updateOrderMainStatus(Order $order): void
    {
        $lines = $order->lines()->get();

        if ($lines->isEmpty()) {
            $order->update(['status' => 'pending']);
            return;
        }

        // Exclude cancelled lines from main status calculation
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

        $dispatchedCount = $activeLines
            ->where('delivery_status', 'dispatched')
            ->count();

        $confirmedCount = $activeLines
            ->where('delivery_status', 'confirmed')
            ->count();

        $pendingCount = $activeLines
            ->where('delivery_status', 'pending')
            ->count();

        /*
     * RETURN HAS PRIORITY
     */
        if ($returnPendingCount === $activeCount) {
            $finalStatus = 'returned';
        } elseif ($returnPendingCount > 0) {
            $finalStatus = 'partial_returned';
        }

        /*
     * NORMAL DELIVERY FLOW
     */ elseif ($deliveredCount === $activeCount) {
            $finalStatus = 'delivered';
        } elseif ($deliveredCount > 0) {
            $finalStatus = 'partial_delivered';
        } elseif ($shippedCount === $activeCount) {
            $finalStatus = 'shipped';
        } elseif ($shippedCount > 0) {
            $finalStatus = 'partial_shipped';
        } elseif ($dispatchedCount === $activeCount) {
            $finalStatus = 'dispatched';
        } elseif ($dispatchedCount > 0) {
            $finalStatus = 'partial_dispatched';
        } elseif ($confirmedCount === $activeCount) {
            $finalStatus = 'confirmed';
        } elseif ($confirmedCount > 0) {
            $finalStatus = 'partial_confirmed';
        } else {
            $finalStatus = 'pending';
        }

        $order->update([
            'status' => $finalStatus,
        ]);
    }

    private function determineOrderStatus(array $deliveryCounts, int $activeCount): string
    {
        // Check for return_pending items
        $hasReturnPending = ($deliveryCounts['return_pending'] ?? 0) > 0;

        // Remove return_pending from consideration for delivery status
        $deliveryCountsWithoutReturn = $deliveryCounts;
        unset($deliveryCountsWithoutReturn['return_pending']);

        $nonReturnCount = array_sum($deliveryCountsWithoutReturn);

        if ($nonReturnCount === 0) {
            return 'returned';
        }

        // Check delivery statuses (excluding return_pending items)
        if (($deliveryCounts['delivered'] ?? 0) === $nonReturnCount) {
            return $hasReturnPending ? 'partial_returned' : 'delivered';
        } elseif (($deliveryCounts['delivered'] ?? 0) > 0) {
            return $hasReturnPending ? 'partial_returned' : 'partial_delivered';
        } elseif (($deliveryCounts['shipped'] ?? 0) === $nonReturnCount) {
            return $hasReturnPending ? 'partial_returned' : 'shipped';
        } elseif (($deliveryCounts['shipped'] ?? 0) > 0) {
            return $hasReturnPending ? 'partial_returned' : 'partial_shipped';
        } elseif (($deliveryCounts['dispatched'] ?? 0) === $nonReturnCount) {
            return $hasReturnPending ? 'partial_returned' : 'dispatched';
        } elseif (($deliveryCounts['dispatched'] ?? 0) > 0) {
            return $hasReturnPending ? 'partial_returned' : 'partial_dispatched';
        } elseif (($deliveryCounts['confirmed'] ?? 0) === $nonReturnCount) {
            return $hasReturnPending ? 'partial_returned' : 'confirmed';
        } elseif (($deliveryCounts['confirmed'] ?? 0) > 0) {
            return $hasReturnPending ? 'partial_returned' : 'partial_confirmed';
        }

        return 'pending';
    }

    // public function markReturnReceived(int $returnId): array
    // {
    //     $returnOrder = OrderReturn::with([
    //         'order',
    //         'user',
    //         'order.lines',
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
    //             'status' => 'received',
    //             'received_at' => now(),
    //         ]);

    //         $this->createReturnNotification($returnOrder, 'received');

    //         /*
    //      * 2. Process Razorpay refund using the line_total
    //      * The total_refund_amount already includes line_totals + shipping
    //      */
    //         $refundResponse = $this->processRefund($returnOrder);

    //         if (!is_array($refundResponse) || empty($refundResponse['refund_id'])) {
    //             throw new Exception(
    //                 'Refund failed. Razorpay refund ID was not returned.'
    //             );
    //         }

    //         $returnOrder->refresh();

    //         /*
    //      * 3. Mark return as completed
    //      */
    //         $returnOrder->update([
    //             'status' => OrderReturn::STATUS_COMPLETED,
    //             'completed_at' => now(),
    //         ]);

    //         /*
    //      * 4. Update individual order lines to 'returned' status
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
    //                 if ($orderLine->delivery_status !== 'return_approved') {
    //                     throw new Exception(
    //                         "Cannot complete return - item '{$orderLine->product->name}' is not delivered."
    //                     );
    //                 }

    //                 $orderLine->update([
    //                     'return_status' => 'returned',
    //                     'delivery_status' => 'returned',
    //                     'return_completed_at' => now(),
    //                 ]);
    //             }
    //         }

    //         /*
    //      * 5. Update order-level return status
    //      */
    //         $this->updateOrderReturnStatus($returnOrder->order);

    //         /*
    //      * 6. Update order main status
    //      */
    //         $this->updateOrderMainStatus($returnOrder->order);

    //         /*
    //      * 7. Completed notification
    //      */
    //         $this->createReturnNotification($returnOrder, 'completed');

    //         /*
    //      * 8. Final logging with detailed refund breakdown
    //      */
    //         Log::info(
    //             'Return marked as received and Razorpay refund completed',
    //             [
    //                 'return_id' => $returnOrder->id,
    //                 'order_id' => $returnOrder->order_id,
    //                 'order_status' => $returnOrder->order->status,
    //                 'payment_id' => $returnOrder->order->gateway_transaction_id ?? null,
    //                 'refund_amount' => $returnOrder->total_refund_amount,
    //                 'refund_transaction_id' => $returnOrder->refund_transaction_id,
    //                 'refund_breakdown' => [
    //                     'subtotal' => $returnOrder->refund_subtotal,
    //                     'tax' => $returnOrder->refund_tax,
    //                     'shipping' => $returnOrder->refund_shipping,
    //                 ],
    //                 'items' => array_map(function ($item) {
    //                     return [
    //                         'order_line_id' => $item['order_line_id'],
    //                         'product_name' => $item['product_name'] ?? 'Unknown',
    //                         'line_total' => $item['line_total'] ?? 0,
    //                         'status' => 'returned'
    //                     ];
    //                 }, $returnOrder->items),
    //             ]
    //         );

    //         /*
    //      * 9. Return API response with detailed refund breakdown
    //      */
    //         return [
    //             'success' => true,
    //             'message' => 'Return marked as received and refund processed successfully.',
    //             'return_id' => $returnOrder->id,
    //             'order_status' => $returnOrder->order->status,
    //             'order_return_status' => $returnOrder->order->return_status,
    //             'status' => OrderReturn::STATUS_COMPLETED,
    //             'refund_amount' => (float) $returnOrder->total_refund_amount,
    //             'refund_breakdown' => [
    //                 'subtotal' => (float) $returnOrder->refund_subtotal,
    //                 'tax' => (float) $returnOrder->refund_tax,
    //                 'shipping' => (float) $returnOrder->refund_shipping,
    //             ],
    //             'refund_transaction_id' => $returnOrder->refund_transaction_id,
    //             'refund_status' => $returnOrder->refund_status,
    //             'items' => array_map(function ($item) {
    //                 return [
    //                     'order_line_id' => $item['order_line_id'] ?? null,
    //                     'product_id' => $item['product_id'] ?? null,
    //                     'product_name' => $item['product_name'] ?? 'Unknown',
    //                     'quantity' => $item['quantity'] ?? 0,
    //                     'line_total' => $item['line_total'] ?? 0,
    //                     'return_status' => 'returned',
    //                     'return_completed_at' => now()->toDateTimeString(),
    //                 ];
    //             }, $returnOrder->items ?? []),
    //         ];
    //     });
    // }

    /**
     * Admin: Mark return as received and process refund
     */
    public function markReturnReceived(int $returnId): array
    {
        $returnOrder = OrderReturn::with([
            'order.deliveryAddress',
            'order.user',
            'order.lines',
            'user',
        ])->findOrFail($returnId);

        if (!$returnOrder->canMarkReceived()) {
            throw new Exception('Only approved returns can be marked as received.');
        }

        return DB::transaction(function () use ($returnOrder) {
            // 1. Mark return as received
            $returnOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            $this->createReturnNotification($returnOrder, 'received');

            // 2. Process refund via Razorpay
            $refundResponse = $this->processRefund($returnOrder);

            if (!is_array($refundResponse) || empty($refundResponse['refund_id'])) {
                throw new Exception('Refund failed. Razorpay refund ID was not returned.');
            }

            // 3. Get the refund record from database
            $refund = Refund::where('return_id', $returnOrder->id)
                ->where('gateway_reference', $refundResponse['refund_id'])
                ->first();

            if (!$refund) {
                throw new Exception('Refund record not found in database.');
            }

            // ============================================================
            // GENERATE CREDIT NOTE
            // ============================================================
            try {
                $creditNoteService = app(\App\Services\CreditNoteService::class);
                $creditNote = $creditNoteService->generateFromReturn($returnOrder, $refund->id);

                Log::info('Credit note generated in markReturnReceived', [
                    'credit_note_id' => $creditNote->id,
                    'refund_id' => $refund->id,
                    'return_id' => $returnOrder->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to generate credit note', [
                    'return_id' => $returnOrder->id,
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            // ============================================================

            // 4. Mark return as completed
            $returnOrder->update([
                'status' => OrderReturn::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // 5. Update individual order lines to 'returned' status
            foreach ($returnOrder->items ?? [] as $item) {
                $orderLineId = is_array($item)
                    ? ($item['order_line_id'] ?? null)
                    : ($item->order_line_id ?? null);

                $returnedQuantity = is_array($item)
                    ? ($item['quantity'] ?? 0)
                    : ($item->quantity ?? 0);
                if (!$orderLineId || $returnedQuantity <= 0) {
                    continue;
                }

                // $orderLine = OrderLine::find($orderLineId);
                $orderLine = OrderLine::with(['product', 'variant'])
                    ->find($orderLineId);
                if ($orderLine && $orderLine->return_status === 'approved') {
                    if ($orderLine->delivery_status !== 'return_approved') {
                        throw new Exception(
                            "Cannot complete return - item '{$orderLine->product->name}' is not delivered."
                        );
                    }

                    $orderLine->update([
                        'return_status' => 'returned',
                        'delivery_status' => 'returned',
                        'return_completed_at' => now(),
                    ]);

                    if ($orderLine->product) {
                        $orderLine->product->increment(
                            'stock_quantity',
                            $returnedQuantity
                        );
                    }

                    // 3. Increase variant stock
                    if ($orderLine->variant_id && $orderLine->variant) {
                        $orderLine->variant->increment(
                            'stock_quantity',
                            $returnedQuantity
                        );
                    }
                }
            }

            // 6. Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);

            // 7. Update order main status
            $this->updateOrderMainStatus($returnOrder->order);

            // 8. Completed notification
            $this->createReturnNotification($returnOrder, 'completed');

            // 9. Return response
            return [
                'success' => true,
                'message' => 'Return marked as received and refund processed successfully.',
                'return_id' => $returnOrder->id,
                'order_status' => $returnOrder->order->status,
                'order_return_status' => $returnOrder->order->return_status,
                'status' => OrderReturn::STATUS_COMPLETED,
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'refund_transaction_id' => $returnOrder->refund_transaction_id,
                'credit_note_generated' => isset($creditNote) ? true : false,
                'credit_note_id' => $creditNote->id ?? null,
                'credit_note_number' => $creditNote->credit_note_number ?? null,
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
                'status' => 'completed',
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
     * Process refund for return via Razorpay
     *
     * @param OrderReturn $returnOrder
     * @return array
     * @throws Exception
     */

    protected function processRefund(OrderReturn $returnOrder): array
    {
        $order = $returnOrder->order;

        if (!$order) {
            throw new Exception('Order not found for this return.');
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
            Log::info('Starting Razorpay refund', [
                'return_id'       => $returnOrder->id,
                'order_id'        => $order->id,
                'payment_id'      => $paymentId,
                'refund_amount'   => $refundAmount,
                'amount_in_paise' => (int) round($refundAmount * 100),
                'refund_breakdown' => [
                    'line_totals' => $this->getItemLineTotals($returnOrder),
                    'shipping'    => (float) $returnOrder->refund_shipping,
                ],
            ]);

            // Call Razorpay
            $refundResponse = $this->razorpayService->refundPayment($paymentId, $refundAmount);

            if (!is_array($refundResponse) || empty($refundResponse['refund_id'])) {
                throw new Exception('Razorpay refund failed. No refund ID was returned.');
            }

            // ============================================================
            // ✅ MAP RAZORPAY STATUS TO YOUR ENUM VALUES
            // ============================================================
            $statusMap = [
                'processing' => 'initiated',
                'processed'  => 'completed',
                'failed'     => 'failed',
            ];
            $refundStatus = $statusMap[$refundResponse['status']] ?? 'completed';

            // ============================================================
            // ✅ INSERT INTO `refunds` TABLE
            // ============================================================
            $refund = Refund::create([
                'order_id'          => $order->id,
                'return_id'         => $returnOrder->id,
                'amount'            => $refundAmount,
                'gateway_reference' => $refundResponse['refund_id'],
                'status'            => $refundStatus,                     // <-- MAPPED VALUE
                'completed_at'      => ($refundStatus === 'completed') ? now() : null,
                'failure_reason'    => null,
            ]);

            // Update OrderReturn with refund details and optional link
            $updateData = [
                'refund_transaction_id' => $refundResponse['refund_id'],
                'refund_status'         => $refundStatus,                 // store mapped status
                'refund_processed_at'   => now(),
            ];

            // If `refund_id` column exists on order_returns, link it
            if (Schema::hasColumn('order_returns', 'refund_id')) {
                $updateData['refund_id'] = $refund->id;
            }

            $returnOrder->update($updateData);

            Log::info('Refund record created', [
                'refund_id'          => $refund->id,
                'return_id'          => $returnOrder->id,
                'refund_transaction_id' => $refundResponse['refund_id'],
                'razorpay_status'    => $refundResponse['status'],
                'mapped_status'      => $refundStatus,
            ]);

            Log::info('Refund successfully processed via Razorpay', [
                'return_id' => $returnOrder->id,
                'payment_id' => $paymentId,
                'refund_id' => $refundResponse['refund_id'],
                'refund_status' => $refundStatus,
                'amount' => $refundAmount,
            ]);

            return $refundResponse;
        } catch (\Throwable $e) {
            Log::error('Refund failed for return', [
                'return_id' => $returnOrder->id,
                'order_id'  => $order->id,
                'payment_id' => $paymentId,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to process refund: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function calculateRefundAmountFromItems(OrderReturn $returnOrder): float
    {
        $totalLineTotal = 0.00;
        $items = $returnOrder->items ?? [];

        foreach ($items as $item) {
            // Handle both array and object access
            $lineTotal = is_array($item)
                ? ($item['line_total'] ?? 0)
                : ($item->line_total ?? 0);

            $totalLineTotal += (float) $lineTotal;
        }

        // Add shipping refund
        $shippingRefund = (float) ($returnOrder->refund_shipping ?? 0);

        // Total refund = line totals + shipping
        $totalRefund = $totalLineTotal + $shippingRefund;

        Log::debug('Refund amount calculation', [
            'return_id' => $returnOrder->id,
            'line_totals_sum' => $totalLineTotal,
            'shipping_refund' => $shippingRefund,
            'total_refund' => $totalRefund,
            'items_count' => count($items),
        ]);

        return round($totalRefund, 2);
    }

    /**
     * Get all line totals from the return items.
     */
    protected function getItemLineTotals(OrderReturn $returnOrder): array
    {
        $lineTotals = [];
        $items = $returnOrder->items ?? [];

        foreach ($items as $item) {
            $lineTotal = is_array($item)
                ? ($item['line_total'] ?? 0)
                : ($item->line_total ?? 0);

            $orderLineId = is_array($item)
                ? ($item['order_line_id'] ?? null)
                : ($item->order_line_id ?? null);

            $productName = is_array($item)
                ? ($item['product_name'] ?? 'Unknown')
                : ($item->product_name ?? 'Unknown');

            $lineTotals[] = [
                'order_line_id' => $orderLineId,
                'product_name' => $productName,
                'line_total' => (float) $lineTotal,
            ];
        }

        return $lineTotals;
    }

    /**
     * Validate if refund amount matches the expected calculation.
     * Useful for debugging and double-checking.
     */
    protected function validateRefundAmount(OrderReturn $returnOrder): bool
    {
        $calculatedAmount = $this->calculateRefundAmountFromItems($returnOrder);
        $storedAmount = (float) ($returnOrder->total_refund_amount ?? 0);

        $difference = abs($calculatedAmount - $storedAmount);

        if ($difference > 0.01) { // Allow small floating point differences
            Log::warning('Refund amount mismatch detected', [
                'return_id' => $returnOrder->id,
                'calculated_amount' => $calculatedAmount,
                'stored_amount' => $storedAmount,
                'difference' => $difference,
                'items' => $this->getItemLineTotals($returnOrder),
            ]);
            return false;
        }

        return true;
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
     * Send notification to user for cooling-off
     */
    protected function sendCoolingOffNotification(OrderReturn $returnOrder, string $status): void
    {
        $user = $returnOrder->user;
        $order = $returnOrder->order;

        if (!$user) {
            Log::warning('User not found for cooling-off notification', [
                'return_id' => $returnOrder->id,
                'user_id' => $returnOrder->user_id,
            ]);
            return;
        }

        // Build notification data
        $notificationData = [
            'type' => 'cooling_off_' . $status,
            'extra_data' => [
                'return_id' => $returnOrder->id,
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'status' => $status,
            ],
        ];

        if ($status === 'approved') {
            $notificationData['title'] = 'Cooling-Off Withdrawal Approved';
            $notificationData['message'] = "Your cooling-off withdrawal for Order #{$order->order_reference} has been approved. Stock has been restored and refund of ₹{$returnOrder->total_refund_amount} will be processed within 5-7 business days.";
            $notificationData['icon'] = 'check-circle';
            $notificationData['color'] = 'success';

            Log::info('Cooling-off approval notification sent to user', [
                'user_id' => $user->id,
                'return_id' => $returnOrder->id,
                'notification_type' => 'cooling_off_approved',
            ]);
        } elseif ($status === 'rejected') {
            $notificationData['title'] = 'Cooling-Off Withdrawal Rejected';
            $notificationData['message'] = "Your cooling-off withdrawal for Order #{$order->order_reference} has been rejected. Reason: " . ($returnOrder->rejection_reason ?? 'Not specified');
            $notificationData['icon'] = 'x-circle';
            $notificationData['color'] = 'danger';

            Log::info('Cooling-off rejection notification sent to user', [
                'user_id' => $user->id,
                'return_id' => $returnOrder->id,
                'notification_type' => 'cooling_off_rejected',
            ]);
        } else {
            // Pending status
            $notificationData['title'] = 'Cooling-Off Withdrawal Initiated';
            $notificationData['message'] = "Your cooling-off withdrawal for Order #{$order->order_reference} has been initiated. Awaiting admin approval.";
            $notificationData['icon'] = 'clock';
            $notificationData['color'] = 'warning';

            Log::info('Cooling-off initiated notification sent to user', [
                'user_id' => $user->id,
                'return_id' => $returnOrder->id,
                'notification_type' => 'cooling_off_initiated',
            ]);
        }

        // Store in database notifications table
        \DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\DynamicNotification',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $user->id,
            'data' => json_encode($notificationData),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Optional: Send email/SMS notification
        // Add email notification here if needed
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

    /**
     * Admin: Approve Cooling-Off Withdrawal
     *
     * @param int $returnId
     * @param int $adminId
     * @param string|null $adminNotes
     * @return array
     * @throws Exception
     */
    public function approveCoolingOff(int $returnId, int $adminId, ?string $adminNotes = null): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->where('type', 'cooling_off')
            ->findOrFail($returnId);

        if (!$returnOrder->canApprove()) {
            throw new Exception('This cooling-off request cannot be approved (already processed).');
        }

        return DB::transaction(function () use ($returnOrder, $adminId, $adminNotes) {
            // 1. Update return status
            $returnOrder->update([
                'status' => 'approved',
                'admin_id' => $adminId,
                'admin_notes' => $adminNotes,
                'approved_at' => now(),
            ]);

            // 2. Update order lines
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

            // ========== NEW: STOCK RESTORE ==========
            try {
                $this->restoreStockForReturn($returnOrder);
                Log::info('Stock restored successfully for cooling-off', [
                    'return_id' => $returnOrder->id,
                    'order_reference' => $returnOrder->order->order_reference ?? null,
                ]);
            } catch (\Exception $e) {
                Log::error('Stock restore failed for cooling-off', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Don't throw - allow the process to continue
                // Stock restore failure should not block the refund
            }
            // ========== END STOCK RESTORE ==========

            // 3. Update order-level return status
            $this->updateOrderReturnStatus($returnOrder->order);
            $this->updateOrderMainStatus($returnOrder->order);

            // 4. Update distributor status (if distributor)
            if ($returnOrder->user->account_type === 'distributor') {
                $returnOrder->user->update([
                    'distributor_status' => 'withdrawn',
                    'is_active' => false,
                ]);

                Log::info('Distributor withdrawn via cooling-off', [
                    'user_id' => $returnOrder->user->id,
                    'return_id' => $returnOrder->id,
                ]);
            }

            // ========== REVERSAL TRIGGER ==========
            try {
                $order = $returnOrder->order;

                // Build payload
                $payload = [
                    'eventId' => 'evt_' . \Illuminate\Support\Str::random(24),
                    'action' => 'REVERSAL',
                    'orderReference' => $order->order_reference,
                    'reason' => 'Cooling-off withdrawal',
                    'lines' => $this->buildReversalLines($returnOrder),
                    'reversedValue' => (float) $returnOrder->total_refund_amount,
                    'originalCv' => (float) ($order->commissionable_volume ?? 0),
                    'purchaserIdentifier' => (string) $returnOrder->user_id,
                    'accountType' => $order->order_type === 'distributor' ? 'DISTRIBUTOR' : 'CUSTOMER',
                    'eventTimestamp' => now()->toIso8601String(),
                ];

                // Save event in database
                $event = CommissionApiEvent::create([
                    'event_type' => 'reversal',
                    'order_id' => $order->id,
                    'payload' => json_encode($payload),
                    'status' => 'pending',
                    'retry_count' => 0,
                    'max_retries' => 5,
                ]);

                Log::info('Cooling-off reversal event saved in database', [
                    'event_id' => $event->id,
                    'return_id' => $returnOrder->id,
                ]);

                // Send to Commission API
                try {
                    $reversalPayload = new \App\Services\Commission\ReversalPayload(
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

                    Log::info('Cooling-off reversal sent to Commission API', [
                        'return_id' => $returnOrder->id,
                        'event_id' => $event->id,
                    ]);
                } catch (\Exception $e) {
                    $event->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'last_attempt' => now(),
                    ]);

                    Log::error('Cooling-off reversal API call failed', [
                        'return_id' => $returnOrder->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Cooling-off reversal event creation failed', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
            // ========== END REVERSAL TRIGGER ==========

            // ========== NEW: USER NOTIFICATIONS ==========
            try {
                $this->sendCoolingOffNotification($returnOrder, 'approved');
                Log::info('Cooling-off approval notification sent to user', [
                    'return_id' => $returnOrder->id,
                    'user_id' => $returnOrder->user_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send cooling-off notification', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
            // ========== END USER NOTIFICATIONS ==========

            // 5. Admin notification (existing)
            $this->createReturnNotification($returnOrder, 'approved');

            // 6. Logging
            Log::info('Cooling-off withdrawal approved', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'refund_amount' => $returnOrder->total_refund_amount,
                'user_id' => $returnOrder->user_id,
                'stock_restored' => true,
            ]);

            return [
                'success' => true,
                'message' => 'Cooling-off withdrawal approved successfully. Stock restored and refund will be processed within 5-7 business days.',
                'return_id' => $returnOrder->id,
                'status' => 'approved',
                'refund_amount' => (float) $returnOrder->total_refund_amount,
                'admin_notes' => $adminNotes,
                'stock_restored' => true,
            ];
        });
    }

    /**
     * Admin: Reject Cooling-Off Withdrawal
     *
     * @param int $returnId
     * @param int $adminId
     * @param string $rejectionReason
     * @return array
     * @throws Exception
     */
    public function rejectCoolingOff(int $returnId, int $adminId, string $rejectionReason): array
    {
        $returnOrder = OrderReturn::with(['order', 'user'])
            ->where('type', 'cooling_off')
            ->findOrFail($returnId);

        if (!$returnOrder->canReject()) {
            throw new Exception('This cooling-off request cannot be rejected (already processed).');
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

            // ========== NEW: USER NOTIFICATION ==========
            try {
                $this->sendCoolingOffNotification($returnOrder, 'rejected');
                Log::info('Cooling-off rejection notification sent to user', [
                    'return_id' => $returnOrder->id,
                    'user_id' => $returnOrder->user_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send cooling-off rejection notification', [
                    'return_id' => $returnOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
            // ========== END USER NOTIFICATION ==========

            // 4. Admin notification (existing)
            $this->createReturnNotification($returnOrder, 'rejected');

            // 5. Logging
            Log::info('Cooling-off withdrawal rejected', [
                'return_id' => $returnOrder->id,
                'admin_id' => $adminId,
                'reason' => $rejectionReason,
            ]);

            return [
                'success' => true,
                'message' => 'Cooling-off withdrawal rejected.',
                'return_id' => $returnOrder->id,
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
            ];
        });
    }
}