<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderShippingDetail;
use App\Services\CheckoutService;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;
use App\Traits\AuditLogTrait;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    use AuditLogTrait;

    protected $checkoutService;
    protected $invoiceService;

    public function __construct(
        CheckoutService $checkoutService,
        InvoiceService $invoiceService
    ) {
        $this->checkoutService = $checkoutService;
        $this->invoiceService = $invoiceService;
    }

    /**
     * FR-CO-008: Get order history
     * GET /api/order/history
     */
    // public function history(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'status' => 'nullable|in:pending,confirmed,processing,dispatched,delivered,cancelled,returned',
    //         'from_date' => 'nullable|date',
    //         'to_date' => 'nullable|date|after_or_equal:from_date',
    //         'order_type' => 'nullable|in:retail,distributor',
    //         'per_page' => 'nullable|integer|min:1|max:100',
    //     ]);

    //     try {
    //         $history = $this->checkoutService->getOrderHistory(
    //             auth()->id(),
    //             $validated
    //         );

    //         return response()->json([
    //             'success' => true,
    //             'data' => $history,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 400);
    //     }
    // }

    /**
     * FR-CO-008: Get order detail
     * GET /api/order/{orderReference}
     */
    // public function show(string $orderReference): JsonResponse
    // {
    //     // No additional validation needed; the service will handle not found

    //     try {
    //         $order = $this->checkoutService->getOrderDetail(
    //             auth()->id(),
    //             $orderReference
    //         );

    //         return response()->json([
    //             'success' => true,
    //             'data' => $order,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 404);
    //     }
    // }

    /**
     * FR-CO-010: Cancel order (before dispatch)
     * POST /api/order/{orderReference}/cancel
     */
    // public function cancel(Request $request, string $orderReference): JsonResponse
    // {
    //     try {
    //         $request->validate([
    //             'reason' => 'required|string|max:500',
    //         ]);
    //         $result = $this->checkoutService->cancelOrder(
    //             auth()->id(),
    //             $orderReference,
    //             $request->reason
    //         );


    //         return response()->json([
    //             'success' => true,
    //             'data' => $result,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 400);
    //     }
    // }
    public function cancel(Request $request, string $orderReference): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|max:500',
            ]);

            // Get order before cancellation for audit
            $order = Order::where('order_reference', $orderReference)->first();
            $oldStatus = $order?->status;

            $result = $this->checkoutService->cancelOrder(
                auth()->id(),
                $orderReference,
                $request->reason
            );

            // Log cancellation
            $this->logAudit(
                'order_cancel',
                'orders',
                [
                    'order_reference' => $orderReference,
                    'status' => $oldStatus,
                    'amount' => $order?->total_payable,
                    'user_id' => auth()->id(),
                ],
                [
                    'order_reference' => $orderReference,
                    'status' => 'cancelled',
                    'reason' => $request->reason,
                    'cancelled_by' => auth()->id(),
                    'cancelled_by_type' => Auth::guard('admin')->check() ? 'admin' : 'user',
                    'cancelled_at' => now()->toDateTimeString(),
                ],
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function getConfirmedOrder(string $order_reference): JsonResponse
    {
        try {
            $order = Order::with([
                'user',
                'lines.product',
                'lines.product.images',
                'billingAddress',
                'deliveryAddress',
            ])->where('order_reference', $order_reference)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            // Check if order is confirmed
            if ($order->status !== 'confirmed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is not confirmed yet. Current status: ' . $order->status,
                ], 400);
            }

            $formattedOrder = $this->formatOrderDetails($order);

            return response()->json([
                'success' => true,
                'data' => $formattedOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }
    private function formatOrderDetails(Order $order): array
    {
        // Format order items with product details
        $items = [];
        foreach ($order->lines as $line) {
            $product = $line->product;

            // Get product images
            $images = [];
            if ($product && $product->images) {
                foreach ($product->images as $image) {
                    $images[] = [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => $image->is_primary,
                    ];
                }
            }

            $items[] = [
                'product_id' => $line->product_id,
                'product_name' => $product?->name ?? 'Product Not Found',
                'product_code' => $product?->product_code ?? 'N/A',
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'gst_rate' => (float) $line->gst_rate,
                'gst_amount' => (float) $line->gst_amount,
                'line_total' => (float) $line->line_total,
                'commissionable_volume' => (float) $line->commissionable_volume,
                'images' => $images,
                'primary_image' => !empty($images) ? $images[0]['image_url'] : null,
            ];
        }

        // Format address
        $billingAddress = $order->billingAddress;
        $deliveryAddress = $order->deliveryAddress;

        return [
            // Order Basic Info
            'order_id' => $order->id,
            'order_reference' => $order->order_reference,
            'order_status' => $order->status,
            'order_type' => $order->order_type,
            'order_date' => $order->created_at->toDateTimeString(),
            'confirmed_date' => $order->confirmed_at?->toDateTimeString(),

            // Payment Info
            'payment_gateway' => $order->payment_gateway ?? 'Razorpay',
            'gateway_transaction_id' => $order->gateway_transaction_id,
            'amount_paid' => (float) $order->amount_paid,
            'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',

            // Financial Breakdown
            'subtotal' => (float) $order->subtotal,
            'total_gst' => (float) $order->total_gst,
            'shipping_charge' => (float) $order->shipping_charge,
            'coin_redeemed' => (int) $order->coin_redeemed,
            'coin_redeemed_amount' => (float) $order->coin_redeemed_amount,
            'total_payable' => (float) $order->total_payable,

            // Tax Breakdown (if stored)
            'tax_breakdown' => !empty($order->tax_breakdown)
                ? json_decode($order->tax_breakdown, true)
                : [],

            // Order Items
            'items' => $items,

            // Addresses
            'billing_address' => $billingAddress ? [
                'id' => $billingAddress->id,
                'full_name' => $billingAddress->full_name ?? null,
                'phone' => $billingAddress->phone ?? null,
                'address_line_1' => $billingAddress->address_line_1,
                'address_line_2' => $billingAddress->address_line_2 ?? null,
                'city' => $billingAddress->city,
                'state' => $billingAddress->state,
                'postal_code' => $billingAddress->postal_code,
                'country' => $billingAddress->country ?? 'India',
                'full_address' => $this->formatAddress($billingAddress),
            ] : null,

            'delivery_address' => $deliveryAddress ? [
                'id' => $deliveryAddress->id,
                'full_name' => $deliveryAddress->full_name ?? null,
                'phone' => $deliveryAddress->phone ?? null,
                'address_line_1' => $deliveryAddress->address_line_1,
                'address_line_2' => $deliveryAddress->address_line_2 ?? null,
                'city' => $deliveryAddress->city,
                'state' => $deliveryAddress->state,
                'postal_code' => $deliveryAddress->postal_code,
                'country' => $deliveryAddress->country ?? 'India',
                'full_address' => $this->formatAddress($deliveryAddress),
            ] : null,

            // // Coin Redemption Details
            // 'coin_redemption' => $order->coinRedemption ? [
            //     'id' => $order->coinRedemption->id,
            //     'coins_used' => (int) $order->coinRedemption->coins_used,
            //     'amount_redeemed' => (float) $order->coinRedemption->amount_redeemed,
            //     'status' => $order->coinRedemption->status,
            // ] : null,

            // User Info
            'user' => [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
                'phone' => $order->user->phone ?? null,
                'is_distributor' => $order->user->isDistributor(),
            ],

            // Invoice Info
            'invoice' => $order->invoice ? [
                'invoice_number' => $order->invoice->invoice_number,
                'invoice_url' => asset('storage/invoices/' . $order->invoice->invoice_number . '.pdf'),
                'generated_at' => $order->invoice->created_at->toDateTimeString(),
            ] : null,

            // Timeline
            'timeline' => [
                'order_placed' => $order->created_at->toDateTimeString(),
                'order_confirmed' => $order->confirmed_at?->toDateTimeString(),
                'shipped_at' => $order->shipped_at?->toDateTimeString(),
                'delivered_at' => $order->delivered_at?->toDateTimeString(),
            ],
        ];
    }

    private function formatAddress($address): string
    {
        $parts = [
            $address->address_line_1,
            $address->address_line_2,
            $address->city,
            $address->state,
            $address->postal_code,
            $address->country ?? 'India',
        ];

        return implode(', ', array_filter($parts));
    }

    // public function getOrder()
    // {
    //     try {
    //         $orderLines = OrderLine::with([
    //             'order.user',
    //             'order.billingAddress',
    //             'order.deliveryAddress',
    //             'order.shippingMethod',
    //             'order.invoice',
    //             'order.returns',
    //             'product',
    //             'product.images'
    //         ])
    //             ->whereHas('order', function ($query) {
    //                 $query->where('user_id', auth()->id());
    //             })
    //             ->latest('id')
    //             ->get();

    //         $formattedItems = [];

    //         foreach ($orderLines as $line) {
    //             $order = $line->order;
    //             $product = $line->product;

    //             // Get product images
    //             $images = [];
    //             $primaryImage = null;

    //             if ($product && $product->images) {
    //                 foreach ($product->images as $image) {
    //                     $images[] = [
    //                         'id' => $image->id,
    //                         'image_url' => asset('storage/' . $image->image),
    //                         'is_primary' => $image->is_primary,
    //                     ];

    //                     if ($image->is_primary) {
    //                         $primaryImage = asset('storage/' . $image->image);
    //                     }
    //                 }

    //                 // If no primary image set, use first image
    //                 if (!$primaryImage && !empty($images)) {
    //                     $primaryImage = $images[0]['image_url'];
    //                 }
    //             }

    //             // Format addresses
    //             $billingAddress = $order->billingAddress;
    //             $deliveryAddress = $order->deliveryAddress;
    //             $shippingMethod = $order->shippingMethod;

    //             // Format returns with full image URLs
    //             $returns = $order->returns->map(function ($return) {
    //                 // Process return items with full image URLs
    //                 $returnItems = [];
    //                 if ($return->items) {
    //                     $items = is_string($return->items)
    //                         ? json_decode($return->items, true)
    //                         : $return->items;

    //                     foreach ($items as $item) {
    //                         // Process image paths
    //                         $imagePaths = $item['image_paths'] ?? [];
    //                         $fullImageUrls = [];

    //                         foreach ($imagePaths as $path) {
    //                             $fullImageUrls[] = asset('storage/' . $path);
    //                         }

    //                         $returnItems[] = [
    //                             'order_line_id' => $item['order_line_id'] ?? null,
    //                             'product_id' => $item['product_id'] ?? null,
    //                             'product_name' => $item['product_name'] ?? 'Unknown',
    //                             'quantity' => $item['quantity'] ?? 0,
    //                             'unit_price' => (float) ($item['unit_price'] ?? 0),
    //                             'gst_rate' => (float) ($item['gst_rate'] ?? 0),
    //                             'subtotal' => (float) ($item['subtotal'] ?? 0),
    //                             'tax' => (float) ($item['tax'] ?? 0),
    //                             'line_total' => (float) ($item['line_total'] ?? 0),
    //                             'reason' => $item['reason'] ?? null,
    //                             'image_paths' => $imagePaths, // Keep original paths
    //                             'image_urls' => $fullImageUrls, // Full URLs
    //                             'return_status' => $item['return_status'] ?? 'pending',
    //                         ];
    //                     }
    //                 }

    //                 // Process general images
    //                 $generalImages = [];
    //                 if ($return->general_images) {
    //                     $images = is_string($return->general_images)
    //                         ? json_decode($return->general_images, true)
    //                         : $return->general_images;

    //                     foreach ($images as $image) {
    //                         $generalImages[] = [
    //                             'path' => $image,
    //                             'url' => asset('storage/' . $image),
    //                         ];
    //                     }
    //                 }

    //                 return [
    //                     'id' => $return->id,
    //                     'order_id' => $return->order_id,
    //                     'user_id' => $return->user_id,
    //                     'items' => $returnItems,
    //                     // 'general_images' => $generalImages,
    //                     // 'partial_approval_details' => is_string($return->partial_approval_details)
    //                     //     ? json_decode($return->partial_approval_details, true)
    //                     //     : $return->partial_approval_details,
    //                     'status' => $return->status,
    //                     // 'reason' => $return->reason,
    //                     'refund_subtotal' => (float) $return->refund_subtotal,
    //                     'refund_tax' => (float) $return->refund_tax,
    //                     'refund_line_total' => (float) ($return->refund_line_total ?? 0),
    //                     'refund_shipping' => (float) $return->refund_shipping,
    //                     'total_refund_amount' => (float) $return->total_refund_amount,
    //                     'refund_status' => $return->refund_status,
    //                     'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
    //                     'admin_notes' => $return->admin_notes,
    //                     'rejection_reason' => $return->rejection_reason,
    //                     // 'approved_at' => $return->approved_at?->toDateTimeString(),
    //                     // 'received_at' => $return->received_at?->toDateTimeString(),
    //                     // 'completed_at' => $return->completed_at?->toDateTimeString(),
    //                     // 'created_at' => $return->created_at?->toDateTimeString(),
    //                 ];
    //             })->values()->toArray();

    //             // Check if product is reviewed
    //             $isReviewed = \App\Models\ProductReview::where('user_id', auth()->id())
    //                 ->where('product_id', $line->product_id)
    //                 ->where('order_id', $order->id)
    //                 ->exists();

    //             // Helper function to format date
    //             $formatDate = function ($date) {
    //                 if (!$date) {
    //                     return null;
    //                 }
    //                 if ($date instanceof \Carbon\Carbon) {
    //                     return $date->toDateTimeString();
    //                 }
    //                 if (is_string($date)) {
    //                     try {
    //                         return \Carbon\Carbon::parse($date)->toDateTimeString();
    //                     } catch (\Exception $e) {
    //                         return $date;
    //                     }
    //                 }
    //                 return null;
    //             };

    //             // Build formatted item
    //             $formattedItems[] = [
    //                 // Order Reference
    //                 'order_id' => $order->id,
    //                 'order_reference' => $order->order_reference,
    //                 'order_status' => $order->status,
    //                 'order_type' => $order->order_type,
    //                 'order_date' => $formatDate($order->created_at),
    //                 'confirmed_date' => $formatDate($order->confirmed_at),

    //                 // Line Item Details
    //                 'line_id' => $line->id,
    //                 'product_id' => $line->product_id,
    //                 'product_name' => $product?->name ?? 'Product Not Found',
    //                 'product_code' => $product?->product_code ?? 'N/A',
    //                 'quantity' => $line->quantity,
    //                 'unit_price' => (float) $line->unit_price,
    //                 'gst_rate' => (float) $line->gst_rate,
    //                 'gst_amount' => (float) $line->gst_amount,
    //                 'line_total' => (float) $line->line_total,
    //                 'commissionable_volume' => (float) $line->commissionable_volume,

    //                 // Product Status (Order Line Level)
    //                 'delivery_status' => $line->delivery_status ?? 'pending',
    //                 'return_status' => $line->return_status ?? 'none',
    //                 'returned_quantity' => (int) ($line->returned_quantity ?? 0),
    //                 'available_for_return' => $line->getAvailableForReturnAttribute(),
    //                 'is_returnable' => $line->is_returnable ?? true,

    //                 // Timeline at Order Line Level
    //                 'timeline' => [
    //                     'order_placed' => $formatDate($order->created_at),
    //                     'order_confirmed' => $formatDate($order->confirmed_at),
    //                     'shipped_at' => $formatDate($line->shipped_at),
    //                     'delivered_at' => $formatDate($line->delivered_at),
    //                     'return_requested_at' => $formatDate($line->return_requested_at),
    //                     'return_approved_at' => $formatDate($line->return_approved_at),
    //                     'return_rejected_at' => $formatDate($line->return_rejected_at),
    //                     'return_completed_at' => $formatDate($line->return_completed_at),
    //                 ],

    //                 'is_reviewed' => $isReviewed,

    //                 // Product Images
    //                 'images' => $images,
    //                 'primary_image' => $primaryImage,

    //                 // Order Financial Info
    //                 'payment_gateway' => $order->payment_gateway ?? 'Razorpay',
    //                 'gateway_transaction_id' => $order->gateway_transaction_id,
    //                 'amount_paid' => (float) $order->amount_paid,
    //                 'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',
    //                 'subtotal' => (float) $order->subtotal,
    //                 'total_gst' => (float) $order->total_gst,
    //                 'shipping_charge' => (float) $order->shipping_charge,
    //                 'coin_redeemed' => (int) $order->coin_redeemed,
    //                 'coin_redeemed_amount' => (float) $order->coin_redeemed_amount,
    //                 'total_payable' => (float) $order->total_payable,

    //                 // Tax Breakdown
    //                 'tax_breakdown' => !empty($order->tax_breakdown)
    //                     ? (
    //                         is_string($order->tax_breakdown)
    //                         ? json_decode($order->tax_breakdown, true)
    //                         : $order->tax_breakdown
    //                     )
    //                     : [],

    //                 // Shipping Method Details
    //                 'shipping_method' => $shippingMethod ? [
    //                     'id' => $shippingMethod->id,
    //                     'name' => $shippingMethod->name,
    //                     'code' => $shippingMethod->code,
    //                     'description' => $shippingMethod->description,
    //                     'base_rate' => (float) $shippingMethod->base_rate,
    //                     'rate_type' => $shippingMethod->rate_type,
    //                     'rate_value' => (float) $shippingMethod->rate_value,
    //                     'min_order_amount' => (float) $shippingMethod->min_order_amount,
    //                     'max_order_amount' => (float) $shippingMethod->max_order_amount,
    //                     'estimated_days' => $shippingMethod->estimated_days,
    //                     'is_active' => (bool) $shippingMethod->is_active,
    //                 ] : null,

    //                 // Addresses
    //                 'billing_address' => $billingAddress ? [
    //                     'id' => $billingAddress->id,
    //                     'full_name' => $billingAddress->full_name ?? null,
    //                     'phone' => $billingAddress->phone ?? null,
    //                     'address_line_1' => $billingAddress->address_line_1,
    //                     'address_line_2' => $billingAddress->address_line_2 ?? null,
    //                     'city' => $billingAddress->city,
    //                     'state' => $billingAddress->state,
    //                     'postal_code' => $billingAddress->postal_code,
    //                     'country' => $billingAddress->country ?? 'India',
    //                     'full_address' => $this->formatAddress($billingAddress),
    //                 ] : null,

    //                 'delivery_address' => $deliveryAddress ? [
    //                     'id' => $deliveryAddress->id,
    //                     'full_name' => $deliveryAddress->full_name ?? null,
    //                     'phone' => $deliveryAddress->phone ?? null,
    //                     'address_line_1' => $deliveryAddress->address_line_1,
    //                     'address_line_2' => $deliveryAddress->address_line_2 ?? null,
    //                     'city' => $deliveryAddress->city,
    //                     'state' => $deliveryAddress->state,
    //                     'postal_code' => $deliveryAddress->postal_code,
    //                     'country' => $deliveryAddress->country ?? 'India',
    //                     'full_address' => $this->formatAddress($deliveryAddress),
    //                 ] : null,

    //                 // User Info
    //                 'user' => [
    //                     'id' => $order->user->id,
    //                     'name' => $order->user->name,
    //                     'email' => $order->user->email,
    //                     'phone' => $order->user->phone ?? null,
    //                     'is_distributor' => $order->user->isDistributor(),
    //                 ],

    //                 // Invoice Info
    //                 'invoice' => $order->invoice ? [
    //                     'invoice_number' => $order->invoice->invoice_number,
    //                     // 'invoice_url' => asset(
    //                     //     'storage/invoices/' . $order->invoice->invoice_number . '.pdf'
    //                     // ),
    //                     'generated_at' => $formatDate($order->invoice->created_at),
    //                 ] : null,

    //                 // Returns
    //                 'returns' => $returns,

    //             ];
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'data' => $formattedItems,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function getOrder()
    // {
    //     try {
    //         $orderLines = OrderLine::with([
    //             'order.user',
    //             'order.billingAddress',
    //             'order.deliveryAddress',
    //             'order.shippingMethod',
    //             'order.invoice',
    //             'order.returns',
    //             'order.creditNotes',
    //             'product',
    //             'product.images'
    //         ])
    //             ->whereHas('order', function ($query) {
    //                 $query->where('user_id', auth()->id());
    //             })
    //             ->latest('id')
    //             ->get();

    //         $formattedItems = [];

    //         foreach ($orderLines as $line) {
    //             $order = $line->order;
    //             $product = $line->product;

    //             // Get product images
    //             $images = [];
    //             $primaryImage = null;

    //             if ($product && $product->images) {
    //                 foreach ($product->images as $image) {
    //                     $images[] = [
    //                         'id' => $image->id,
    //                         'image_url' => asset('storage/' . $image->image),
    //                         'is_primary' => $image->is_primary,
    //                     ];

    //                     if ($image->is_primary) {
    //                         $primaryImage = asset('storage/' . $image->image);
    //                     }
    //                 }

    //                 if (!$primaryImage && !empty($images)) {
    //                     $primaryImage = $images[0]['image_url'];
    //                 }
    //             }

    //             // Format addresses
    //             $billingAddress = $order->billingAddress;
    //             $deliveryAddress = $order->deliveryAddress;
    //             $shippingMethod = $order->shippingMethod;

    //             // Format returns with full image URLs
    //             $returns = $order->returns->map(function ($return) {
    //                 $returnItems = [];
    //                 if ($return->items) {
    //                     $items = is_string($return->items)
    //                         ? json_decode($return->items, true)
    //                         : $return->items;

    //                     foreach ($items as $item) {
    //                         $imagePaths = $item['image_paths'] ?? [];
    //                         $fullImageUrls = [];

    //                         foreach ($imagePaths as $path) {
    //                             $fullImageUrls[] = asset('storage/' . $path);
    //                         }

    //                         $returnItems[] = [
    //                             'order_line_id' => $item['order_line_id'] ?? null,
    //                             'product_id' => $item['product_id'] ?? null,
    //                             'product_name' => $item['product_name'] ?? 'Unknown',
    //                             'quantity' => $item['quantity'] ?? 0,
    //                             'unit_price' => (float) ($item['unit_price'] ?? 0),
    //                             'gst_rate' => (float) ($item['gst_rate'] ?? 0),
    //                             'subtotal' => (float) ($item['subtotal'] ?? 0),
    //                             'tax' => (float) ($item['tax'] ?? 0),
    //                             'line_total' => (float) ($item['line_total'] ?? 0),
    //                             'reason' => $item['reason'] ?? null,
    //                             'image_paths' => $imagePaths,
    //                             'image_urls' => $fullImageUrls,
    //                             'return_status' => $item['return_status'] ?? 'pending',
    //                         ];
    //                     }
    //                 }

    //                 $generalImages = [];
    //                 if ($return->general_images) {
    //                     $images = is_string($return->general_images)
    //                         ? json_decode($return->general_images, true)
    //                         : $return->general_images;

    //                     foreach ($images as $image) {
    //                         $generalImages[] = [
    //                             'path' => $image,
    //                             'url' => asset('storage/' . $image),
    //                         ];
    //                     }
    //                 }

    //                 return [
    //                     'id' => $return->id,
    //                     'order_id' => $return->order_id,
    //                     'user_id' => $return->user_id,
    //                     'items' => $returnItems,
    //                     'status' => $return->status,
    //                     'refund_subtotal' => (float) $return->refund_subtotal,
    //                     'refund_tax' => (float) $return->refund_tax,
    //                     'refund_line_total' => (float) ($return->refund_line_total ?? 0),
    //                     'refund_shipping' => (float) $return->refund_shipping,
    //                     'total_refund_amount' => (float) $return->total_refund_amount,
    //                     'refund_status' => $return->refund_status,
    //                     'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
    //                     'admin_notes' => $return->admin_notes,
    //                     'rejection_reason' => $return->rejection_reason,
    //                 ];
    //             })->values()->toArray();

    //             // Check if product is reviewed
    //             $isReviewed = \App\Models\ProductReview::where('user_id', auth()->id())
    //                 ->where('product_id', $line->product_id)
    //                 ->where('order_id', $order->id)
    //                 ->exists();

    //             // Helper function to format date
    //             $formatDate = function ($date) {
    //                 if (!$date) {
    //                     return null;
    //                 }
    //                 if ($date instanceof \Carbon\Carbon) {
    //                     return $date->toDateTimeString();
    //                 }
    //                 if (is_string($date)) {
    //                     try {
    //                         return \Carbon\Carbon::parse($date)->toDateTimeString();
    //                     } catch (\Exception $e) {
    //                         return $date;
    //                     }
    //                 }
    //                 return null;
    //             };

    //             // ============================================================
    //             // CREDIT NOTES FOR THIS ORDER LINE
    //             // ============================================================
    //             $itemCreditNotes = [];
    //             $orderCreditNotes = $order->creditNotes ?? collect();

    //             foreach ($orderCreditNotes as $cn) {
    //                 $cnItems = is_string($cn->items) ? json_decode($cn->items, true) : $cn->items;
    //                 if (is_array($cnItems)) {
    //                     foreach ($cnItems as $cnItem) {
    //                         if (($cnItem['order_line_id'] ?? null) == $line->id) {
    //                             $itemCreditNotes[] = [
    //                                 'id' => $cn->id,
    //                                 'credit_note_number' => $cn->credit_note_number,
    //                                 'original_invoice_number' => $cn->original_invoice_number,
    //                                 'amount' => (float) $cn->amount,
    //                                 'issued_at' => $formatDate($cn->issued_at),
    //                                 'download_url' => "/api/credit-notes/{$cn->id}/download-data",
    //                             ];
    //                             break;
    //                         }
    //                     }
    //                 }
    //             }

    //             // Build formatted item
    //             $formattedItems[] = [
    //                 // Order Reference
    //                 'order_id' => $order->id,
    //                 'order_reference' => $order->order_reference,
    //                 'order_status' => $order->status,
    //                 'order_type' => $order->order_type,
    //                 'order_date' => $formatDate($order->created_at),
    //                 'confirmed_date' => $formatDate($order->confirmed_at),

    //                 // Line Item Details
    //                 'line_id' => $line->id,
    //                 'product_id' => $line->product_id,
    //                 'product_name' => $product?->name ?? 'Product Not Found',
    //                 'product_code' => $product?->product_code ?? 'N/A',
    //                 'quantity' => $line->quantity,
    //                 'unit_price' => (float) $line->unit_price,
    //                 'gst_rate' => (float) $line->gst_rate,
    //                 'gst_amount' => (float) $line->gst_amount,
    //                 'line_total' => (float) $line->line_total,
    //                 'commissionable_volume' => (float) $line->commissionable_volume,

    //                 // Product Status (Order Line Level)
    //                 'delivery_status' => $line->delivery_status ?? 'pending',
    //                 'return_status' => $line->return_status ?? 'none',
    //                 'returned_quantity' => (int) ($line->returned_quantity ?? 0),
    //                 'available_for_return' => $line->getAvailableForReturnAttribute(),
    //                 'is_returnable' => $line->is_returnable ?? true,

    //                 // Timeline at Order Line Level
    //                 'timeline' => [
    //                     'order_placed' => $formatDate($order->created_at),
    //                     'order_confirmed' => $formatDate($order->confirmed_at),
    //                     'shipped_at' => $formatDate($line->shipped_at),
    //                     'cancelled_at' => $formatDate($line->cancelled_at),
    //                     'dispatched_at' => $formatDate($line->dispatched_at),
    //                     'delivered_at' => $formatDate($line->delivered_at),
    //                     'return_requested_at' => $formatDate($line->return_requested_at),
    //                     'return_approved_at' => $formatDate($line->return_approved_at),
    //                     'return_rejected_at' => $formatDate($line->return_rejected_at),
    //                     'return_completed_at' => $formatDate($line->return_completed_at),
    //                 ],

    //                 'is_reviewed' => $isReviewed,

    //                 // Product Images
    //                 'images' => $images,
    //                 'primary_image' => $primaryImage,

    //                 // Order Financial Info
    //                 'payment_gateway' => $order->payment_gateway ?? 'Razorpay',
    //                 'gateway_transaction_id' => $order->gateway_transaction_id,
    //                 'amount_paid' => (float) $order->amount_paid,
    //                 'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',
    //                 'subtotal' => (float) $order->subtotal,
    //                 'total_gst' => (float) $order->total_gst,
    //                 'shipping_charge' => (float) $order->shipping_charge,
    //                 'coin_redeemed' => (int) $order->coin_redeemed,
    //                 'coin_redeemed_amount' => (float) $order->coin_redeemed_amount,
    //                 'total_payable' => (float) $order->total_payable,

    //                 // Tax Breakdown
    //                 'tax_breakdown' => !empty($order->tax_breakdown)
    //                     ? (
    //                         is_string($order->tax_breakdown)
    //                         ? json_decode($order->tax_breakdown, true)
    //                         : $order->tax_breakdown
    //                     )
    //                     : [],

    //                 // Shipping Method Details
    //                 'shipping_method' => $shippingMethod ? [
    //                     'id' => $shippingMethod->id,
    //                     'name' => $shippingMethod->name,
    //                     'code' => $shippingMethod->code,
    //                     'description' => $shippingMethod->description,
    //                     'base_rate' => (float) $shippingMethod->base_rate,
    //                     'rate_type' => $shippingMethod->rate_type,
    //                     'rate_value' => (float) $shippingMethod->rate_value,
    //                     'min_order_amount' => (float) $shippingMethod->min_order_amount,
    //                     'max_order_amount' => (float) $shippingMethod->max_order_amount,
    //                     'estimated_days' => $shippingMethod->estimated_days,
    //                     'is_active' => (bool) $shippingMethod->is_active,
    //                 ] : null,

    //                 // Addresses
    //                 'billing_address' => $billingAddress ? [
    //                     'id' => $billingAddress->id,
    //                     'full_name' => $billingAddress->full_name ?? null,
    //                     'phone' => $billingAddress->phone ?? null,
    //                     'address_line_1' => $billingAddress->address_line_1,
    //                     'address_line_2' => $billingAddress->address_line_2 ?? null,
    //                     'city' => $billingAddress->city,
    //                     'state' => $billingAddress->state,
    //                     'postal_code' => $billingAddress->postal_code,
    //                     'country' => $billingAddress->country ?? 'India',
    //                     'full_address' => $this->formatAddress($billingAddress),
    //                 ] : null,

    //                 'delivery_address' => $deliveryAddress ? [
    //                     'id' => $deliveryAddress->id,
    //                     'full_name' => $deliveryAddress->full_name ?? null,
    //                     'phone' => $deliveryAddress->phone ?? null,
    //                     'address_line_1' => $deliveryAddress->address_line_1,
    //                     'address_line_2' => $deliveryAddress->address_line_2 ?? null,
    //                     'city' => $deliveryAddress->city,
    //                     'state' => $deliveryAddress->state,
    //                     'postal_code' => $deliveryAddress->postal_code,
    //                     'country' => $deliveryAddress->country ?? 'India',
    //                     'full_address' => $this->formatAddress($deliveryAddress),
    //                 ] : null,

    //                 // User Info
    //                 'user' => [
    //                     'id' => $order->user->id,
    //                     'name' => $order->user->name,
    //                     'email' => $order->user->email,
    //                     'phone' => $order->user->phone ?? null,
    //                     'is_distributor' => $order->user->isDistributor(),
    //                 ],

    //                 // Invoice Info
    //                 'invoice' => $order->invoice ? [
    //                     'invoice_number' => $order->invoice->invoice_number,
    //                     'generated_at' => $formatDate($order->invoice->created_at),
    //                 ] : null,

    //                 // Returns
    //                 'returns' => $returns,

    //                 // ============================================================
    //                 // CREDIT NOTES (NEW)
    //                 // ============================================================
    //                 'credit_notes' => $itemCreditNotes,
    //             ];
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'data' => $formattedItems,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function getOrder()
    {
        try {
            $orderLines = OrderLine::with([
                'order.user',
                'order.billingAddress',
                'order.deliveryAddress',
                'order.shippingMethod',
                'order.invoice',
                'order.returns',
                'order.creditNotes',
                'product',
                'product.images',
                'product.reviews' => function ($query) {
                    $query->where('status', 'approved')
                        ->with(['user', 'images']); // Load user and images
                },
            ])
                ->whereHas('order', function ($query) {
                    $query->where('user_id', auth()->id());
                })
                ->latest('id')
                ->get();

            $formattedItems = [];

            foreach ($orderLines as $line) {
                $order = $line->order;
                $product = $line->product;

                // Debug: Check if reviews are loaded
                \Log::info('Line ID: ' . $line->id . ', Product ID: ' . $line->product_id);
                if ($product) {
                    \Log::info('Reviews count for product: ' . $product->reviews->count());
                    foreach ($product->reviews as $review) {
                        \Log::info('Review: ID=' . $review->id . ', order_line_id=' . $review->order_line_id . ', order_id=' . $review->order_id . ', status=' . $review->status);
                    }
                }

                // Get product images
                $images = [];
                $primaryImage = null;

                if ($product && $product->images) {
                    foreach ($product->images as $image) {
                        $images[] = [
                            'id' => $image->id,
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary,
                        ];

                        if ($image->is_primary) {
                            $primaryImage = asset('storage/' . $image->image);
                        }
                    }

                    if (!$primaryImage && !empty($images)) {
                        $primaryImage = $images[0]['image_url'];
                    }
                }

                // FIX: Get ONLY approved reviews for this specific order line
                $productReviews = [];
                if ($product && $product->reviews) {
                    foreach ($product->reviews as $review) {
                        // Debug: Check each review
                        \Log::info('Checking review: ' . $review->id . ' against line_id: ' . $line->id);

                        // Only include approved reviews that belong to this specific order line
                        if (
                            $review->status === 'approved' &&
                            (int)$review->order_line_id === (int)$line->id
                        ) {
                            \Log::info('MATCH FOUND! Review ID: ' . $review->id . ' for line_id: ' . $line->id);

                            $productReviews[] = [
                                'id' => $review->id,
                                'user_id' => $review->user_id,
                                'user_name' => $review->user?->full_name ?? $review->user?->name ?? 'Unknown User',
                                'rating' => (int) $review->rating,
                                'review_text' => $review->review_text,
                                'order_id' => $review->order_id,
                                'order_line_id' => $review->order_line_id,
                                'status' => $review->status,
                                'is_verified_purchase' => true,
                                'images' => $review->images ?
                                    $review->images->map(function ($img) {
                                        return [
                                            'id' => $img->id,
                                            'image_url' => $img->image_url ?? asset('storage/' . $img->image),
                                            'sort_order' => $img->sort_order ?? 0,
                                        ];
                                    })->values()->toArray()
                                    : [],
                                'created_at' => $review->created_at?->toDateTimeString(),
                                'updated_at' => $review->updated_at?->toDateTimeString(),
                            ];
                        }
                    }
                } else {
                    \Log::info('No product or no reviews found for line_id: ' . $line->id);
                }

                // Format addresses
                $billingAddress = $order->billingAddress;
                $deliveryAddress = $order->deliveryAddress;
                $shippingMethod = $order->shippingMethod;

                // Format returns with full image URLs
                $returns = $order->returns->map(function ($return) {
                    $returnItems = [];
                    if ($return->items) {
                        $items = is_string($return->items)
                            ? json_decode($return->items, true)
                            : $return->items;

                        foreach ($items as $item) {
                            $imagePaths = $item['image_paths'] ?? [];
                            $fullImageUrls = [];

                            foreach ($imagePaths as $path) {
                                $fullImageUrls[] = asset('storage/' . $path);
                            }

                            $returnItems[] = [
                                'order_line_id' => $item['order_line_id'] ?? null,
                                'product_id' => $item['product_id'] ?? null,
                                'product_name' => $item['product_name'] ?? 'Unknown',
                                'quantity' => $item['quantity'] ?? 0,
                                'unit_price' => (float) ($item['unit_price'] ?? 0),
                                'gst_rate' => (float) ($item['gst_rate'] ?? 0),
                                'subtotal' => (float) ($item['subtotal'] ?? 0),
                                'tax' => (float) ($item['tax'] ?? 0),
                                'line_total' => (float) ($item['line_total'] ?? 0),
                                'reason' => $item['reason'] ?? null,
                                'image_paths' => $imagePaths,
                                'image_urls' => $fullImageUrls,
                                'return_status' => $item['return_status'] ?? 'pending',
                            ];
                        }
                    }

                    $generalImages = [];
                    if ($return->general_images) {
                        $images = is_string($return->general_images)
                            ? json_decode($return->general_images, true)
                            : $return->general_images;

                        foreach ($images as $image) {
                            $generalImages[] = [
                                'path' => $image,
                                'url' => asset('storage/' . $image),
                            ];
                        }
                    }

                    return [
                        'id' => $return->id,
                        'order_id' => $return->order_id,
                        'user_id' => $return->user_id,
                        'items' => $returnItems,
                        'status' => $return->status,
                        'refund_subtotal' => (float) $return->refund_subtotal,
                        'refund_tax' => (float) $return->refund_tax,
                        'refund_line_total' => (float) ($return->refund_line_total ?? 0),
                        'refund_shipping' => (float) $return->refund_shipping,
                        'total_refund_amount' => (float) $return->total_refund_amount,
                        'refund_status' => $return->refund_status,
                        'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
                        'admin_notes' => $return->admin_notes,
                        'rejection_reason' => $return->rejection_reason,
                    ];
                })->values()->toArray();

                // FIX: Check if THIS SPECIFIC order line has an approved review
                $isReviewed = \App\Models\ProductReview::where('user_id', auth()->id())
                    ->where('product_id', $line->product_id)
                    ->where('order_line_id', $line->id)
                    ->where('status', 'approved')
                    ->exists();

                \Log::info('isReviewed for line_id ' . $line->id . ': ' . ($isReviewed ? 'true' : 'false'));

                // Helper function to format date
                $formatDate = function ($date) {
                    if (!$date) {
                        return null;
                    }
                    if ($date instanceof \Carbon\Carbon) {
                        return $date->toDateTimeString();
                    }
                    if (is_string($date)) {
                        try {
                            return \Carbon\Carbon::parse($date)->toDateTimeString();
                        } catch (\Exception $e) {
                            return $date;
                        }
                    }
                    return null;
                };

                // ============================================================
                // CREDIT NOTES FOR THIS ORDER LINE
                // ============================================================
                $itemCreditNotes = [];
                $orderCreditNotes = $order->creditNotes ?? collect();

                foreach ($orderCreditNotes as $cn) {
                    $cnItems = is_string($cn->items) ? json_decode($cn->items, true) : $cn->items;
                    if (is_array($cnItems)) {
                        foreach ($cnItems as $cnItem) {
                            if (($cnItem['order_line_id'] ?? null) == $line->id) {
                                $itemCreditNotes[] = [
                                    'id' => $cn->id,
                                    'credit_note_number' => $cn->credit_note_number,
                                    'original_invoice_number' => $cn->original_invoice_number,
                                    'amount' => (float) $cn->amount,
                                    'issued_at' => $formatDate($cn->issued_at),
                                    'download_url' => "/api/credit-notes/{$cn->id}/download-data",
                                ];
                                break;
                            }
                        }
                    }
                }

                // Build formatted item
                $formattedItems[] = [
                    // Order Reference
                    'order_id' => $order->id,
                    'order_reference' => $order->order_reference,
                    'order_status' => $order->status,
                    'order_type' => $order->order_type,
                    'order_date' => $formatDate($order->created_at),
                    'confirmed_date' => $formatDate($order->confirmed_at),

                    // Line Item Details
                    'line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $product?->name ?? 'Product Not Found',
                    'product_code' => $product?->product_code ?? 'N/A',
                    'quantity' => $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'gst_rate' => (float) $line->gst_rate,
                    'gst_amount' => (float) $line->gst_amount,
                    'line_total' => (float) $line->line_total,
                    'commissionable_volume' => (float) $line->commissionable_volume,

                    // Product Status (Order Line Level)
                    'delivery_status' => $line->delivery_status ?? 'pending',
                    'return_status' => $line->return_status ?? 'none',
                    'returned_quantity' => (int) ($line->returned_quantity ?? 0),
                    'available_for_return' => $line->getAvailableForReturnAttribute(),
                    'is_returnable' => $line->is_returnable ?? true,

                    // Timeline at Order Line Level
                    'timeline' => [
                        'order_placed' => $formatDate($order->created_at),
                        'order_confirmed' => $formatDate($order->confirmed_at),
                        'shipped_at' => $formatDate($line->shipped_at),
                        'cancelled_at' => $formatDate($line->cancelled_at),
                        'dispatched_at' => $formatDate($line->dispatched_at),
                        'delivered_at' => $formatDate($line->delivered_at),
                        'return_requested_at' => $formatDate($line->return_requested_at),
                        'return_approved_at' => $formatDate($line->return_approved_at),
                        'return_rejected_at' => $formatDate($line->return_rejected_at),
                        'return_completed_at' => $formatDate($line->return_completed_at),
                    ],

                    'is_reviewed' => $isReviewed,

                    // Product Images
                    'images' => $images,
                    'primary_image' => $primaryImage,

                    // FIXED: Only show approved reviews for THIS specific order line
                    'product_reviews' => $productReviews,

                    // Order Financial Info
                    'payment_gateway' => $order->payment_gateway ?? 'Razorpay',
                    'gateway_transaction_id' => $order->gateway_transaction_id,
                    'amount_paid' => (float) $order->amount_paid,
                    'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',
                    'subtotal' => (float) $order->subtotal,
                    'total_gst' => (float) $order->total_gst,
                    'shipping_charge' => (float) $order->shipping_charge,
                    'coin_redeemed' => (int) $order->coin_redeemed,
                    'coin_redeemed_amount' => (float) $order->coin_redeemed_amount,
                    'total_payable' => (float) $order->total_payable,

                    // Tax Breakdown
                    'tax_breakdown' => !empty($order->tax_breakdown)
                        ? (
                            is_string($order->tax_breakdown)
                            ? json_decode($order->tax_breakdown, true)
                            : $order->tax_breakdown
                        )
                        : [],

                    // Shipping Method Details
                    'shipping_method' => $shippingMethod ? [
                        'id' => $shippingMethod->id,
                        'name' => $shippingMethod->name,
                        'code' => $shippingMethod->code,
                        'description' => $shippingMethod->description,
                        'base_rate' => (float) $shippingMethod->base_rate,
                        'rate_type' => $shippingMethod->rate_type,
                        'rate_value' => (float) $shippingMethod->rate_value,
                        'min_order_amount' => (float) $shippingMethod->min_order_amount,
                        'max_order_amount' => (float) $shippingMethod->max_order_amount,
                        'estimated_days' => $shippingMethod->estimated_days,
                        'is_active' => (bool) $shippingMethod->is_active,
                    ] : null,

                    // Addresses
                    'billing_address' => $billingAddress ? [
                        'id' => $billingAddress->id,
                        'full_name' => $billingAddress->full_name ?? null,
                        'phone' => $billingAddress->phone ?? null,
                        'address_line_1' => $billingAddress->address_line_1,
                        'address_line_2' => $billingAddress->address_line_2 ?? null,
                        'city' => $billingAddress->city,
                        'state' => $billingAddress->state,
                        'postal_code' => $billingAddress->postal_code,
                        'country' => $billingAddress->country ?? 'India',
                        'full_address' => $this->formatAddress($billingAddress),
                    ] : null,

                    'delivery_address' => $deliveryAddress ? [
                        'id' => $deliveryAddress->id,
                        'full_name' => $deliveryAddress->full_name ?? null,
                        'phone' => $deliveryAddress->phone ?? null,
                        'address_line_1' => $deliveryAddress->address_line_1,
                        'address_line_2' => $deliveryAddress->address_line_2 ?? null,
                        'city' => $deliveryAddress->city,
                        'state' => $deliveryAddress->state,
                        'postal_code' => $deliveryAddress->postal_code,
                        'country' => $deliveryAddress->country ?? 'India',
                        'full_address' => $this->formatAddress($deliveryAddress),
                    ] : null,

                    // User Info
                    'user' => [
                        'id' => $order->user->id,
                        'name' => $order->user->name,
                        'email' => $order->user->email,
                        'phone' => $order->user->phone ?? null,
                        'is_distributor' => $order->user->isDistributor(),
                    ],

                    // Invoice Info
                    'invoice' => $order->invoice ? [
                        'invoice_number' => $order->invoice->invoice_number,
                        'generated_at' => $formatDate($order->invoice->created_at),
                    ] : null,

                    // Returns
                    'returns' => $returns,

                    // ============================================================
                    // CREDIT NOTES
                    // ============================================================
                    'credit_notes' => $itemCreditNotes,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $formattedItems,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getOrder: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function allOrder()
    {
        try {
            $orders = Order::with([
                'user',
                'billingAddress',
                'deliveryAddress',
                'shippingMethod',
                'invoice',
                'returns',
                'lines',
                'lines.product',
                'lines.product.images',
                'lines.shippingDetails'
            ])
                ->where('status', '!=', 'pending')
                ->latest('id')
                ->get();

            $formattedOrders = [];

            foreach ($orders as $order) {
                $formattedItems = [];

                foreach ($order->lines as $line) {  // 'lines' use karo
                    $product = $line->product;

                    // Get product images
                    $images = [];
                    $primaryImage = null;

                    if ($product && $product->images) {
                        foreach ($product->images as $image) {
                            $images[] = [
                                'id' => $image->id,
                                'image_url' => asset('storage/' . $image->image),
                                'is_primary' => $image->is_primary,
                            ];

                            if ($image->is_primary) {
                                $primaryImage = asset('storage/' . $image->image);
                            }
                        }

                        if (!$primaryImage && !empty($images)) {
                            $primaryImage = $images[0]['image_url'];
                        }
                    }

                    // Format returns with full image URLs
                    $returns = $order->returns->map(function ($return) {
                        $returnItems = [];
                        if ($return->items) {
                            $items = is_string($return->items)
                                ? json_decode($return->items, true)
                                : $return->items;

                            foreach ($items as $item) {
                                $imagePaths = $item['image_paths'] ?? [];
                                $fullImageUrls = [];

                                foreach ($imagePaths as $path) {
                                    $fullImageUrls[] = asset('storage/' . $path);
                                }

                                $returnItems[] = [
                                    'order_line_id' => $item['order_line_id'] ?? null,
                                    'product_id' => $item['product_id'] ?? null,
                                    'product_name' => $item['product_name'] ?? 'Unknown',
                                    'quantity' => $item['quantity'] ?? 0,
                                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                                    'gst_rate' => (float) ($item['gst_rate'] ?? 0),
                                    'subtotal' => (float) ($item['subtotal'] ?? 0),
                                    'tax' => (float) ($item['tax'] ?? 0),
                                    'line_total' => (float) ($item['line_total'] ?? 0),
                                    'reason' => $item['reason'] ?? null,
                                    'image_paths' => $imagePaths,
                                    'image_urls' => $fullImageUrls,
                                    'return_status' => $item['return_status'] ?? 'pending',
                                ];
                            }
                        }

                        return [
                            'id' => $return->id,
                            'order_id' => $return->order_id,
                            'user_id' => $return->user_id,
                            'items' => $returnItems,
                            'status' => $return->status,
                            'refund_subtotal' => (float) $return->refund_subtotal,
                            'refund_tax' => (float) $return->refund_tax,
                            'refund_line_total' => (float) ($return->refund_line_total ?? 0),
                            'refund_shipping' => (float) $return->refund_shipping,
                            'total_refund_amount' => (float) $return->total_refund_amount,
                            'refund_status' => $return->refund_status,
                            'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
                            'admin_notes' => $return->admin_notes,
                            'rejection_reason' => $return->rejection_reason,
                        ];
                    })->values()->toArray();

                    // Check if product is reviewed
                    $isReviewed = \App\Models\ProductReview::where('user_id', auth()->id())
                        ->where('product_id', $line->product_id)
                        ->where('order_id', $order->id)
                        ->exists();

                    // Helper function to format date
                    $formatDate = function ($date) {
                        if (!$date) {
                            return null;
                        }
                        if ($date instanceof \Carbon\Carbon) {
                            return $date->toDateTimeString();
                        }
                        if (is_string($date)) {
                            try {
                                return \Carbon\Carbon::parse($date)->toDateTimeString();
                            } catch (\Exception $e) {
                                return $date;
                            }
                        }
                        return null;
                    };
                    // dd($line->shippingDetails);
                    $formattedItems[] = [
                        // Order Reference
                        'order_id' => $order->id,
                        'order_reference' => $order->order_reference,
                        'order_status' => $order->status,
                        'order_type' => $order->order_type,
                        'order_date' => $formatDate($order->created_at),
                        'confirmed_date' => $formatDate($order->confirmed_at),

                        // Line Item Details
                        'line_id' => $line->id,
                        'product_id' => $line->product_id,
                        'product_name' => $product?->name ?? 'Product Not Found',
                        'product_code' => $product?->product_code ?? 'N/A',
                        'quantity' => $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'gst_rate' => (float) $line->gst_rate,
                        'gst_amount' => (float) $line->gst_amount,
                        'line_total' => (float) $line->line_total,
                        'commissionable_volume' => (float) $line->commissionable_volume,

                        // Product Status
                        'delivery_status' => $line->delivery_status ?? 'pending',
                        'return_status' => $line->return_status ?? 'none',
                        'returned_quantity' => (int) ($line->returned_quantity ?? 0),
                        'available_for_return' => $line->getAvailableForReturnAttribute(),
                        'is_returnable' => $line->is_returnable ?? true,

                        'is_reviewed' => $isReviewed,

                        // Product Images
                        'images' => $images,
                        'primary_image' => $primaryImage,

                        // Order Financial Info
                        'payment_gateway' => $order->payment_gateway ?? 'Razorpay',
                        'gateway_transaction_id' => $order->gateway_transaction_id,
                        'amount_paid' => (float) $order->amount_paid,
                        'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',
                        'subtotal' => (float) $order->subtotal,
                        'total_gst' => (float) $order->total_gst,
                        'shipping_charge' => (float) $order->shipping_charge,
                        'coin_redeemed' => (int) $order->coin_redeemed,
                        'coin_redeemed_amount' => (float) $order->coin_redeemed_amount,
                        'total_payable' => (float) $order->total_payable,

                        // Shipping
                        'shipping_address' => $order->deliveryAddress ? [
                            'id' => $order->deliveryAddress->id,
                            'address_line_1' => $order->deliveryAddress->address_line_1,
                            'address_line_2' => $order->deliveryAddress->address_line_2,
                            'city' => $order->deliveryAddress->city,
                            'state' => $order->deliveryAddress->state,
                            'postal_code' => $order->deliveryAddress->postal_code,
                            'country' => $order->deliveryAddress->country ?? 'India',
                            'full_address' => $this->formatAddress($order->deliveryAddress),
                        ] : null,
                        'shipping_details' => $line->shippingDetails ? [
                            'id' => $line->shippingDetails->id,
                            'courier_tracking_number' => $line->shippingDetails->courier_tracking_number,
                            'courier_company' => $line->shippingDetails->courier_company,
                            'delivery_notes' => $line->shippingDetails->delivery_notes,
                            'courier_delivery_date' => $line->shippingDetails->courier_delivery_date,
                            'status' => $line->shippingDetails->status,
                        ] : null,

                        // User Info
                        'user' => [
                            'id' => $order->user->id,
                            'name' => $order->user->full_name,
                            'email' => $order->user->email,
                            'phone' => $order->user->phone ?? null,
                            'is_distributor' => $order->user->isDistributor(),
                        ],

                        // Returns
                        'returns' => $returns,
                    ];
                }

                $formattedOrders[] = [
                    'order' => [
                        'id' => $order->id,
                        'order_reference' => $order->order_reference,
                        'order_status' => $order->status,
                        'order_type' => $order->order_type,
                        'order_date' => $formatDate($order->created_at),
                        'confirmed_date' => $formatDate($order->confirmed_at),
                        'payment_gateway' => $order->payment_gateway ?? 'Razorpay',
                        'gateway_transaction_id' => $order->gateway_transaction_id,
                        'amount_paid' => (float) $order->amount_paid,
                        'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',
                        'subtotal' => (float) $order->subtotal,
                        'total_gst' => (float) $order->total_gst,
                        'shipping_charge' => (float) $order->shipping_charge,
                        'coin_redeemed' => (int) $order->coin_redeemed,
                        'coin_redeemed_amount' => (float) $order->coin_redeemed_amount,
                        'total_payable' => (float) $order->total_payable,
                        'user' => [
                            'id' => $order->user->id,
                            'name' => $order->user->full_name,
                            'email' => $order->user->email,
                            'phone' => $order->user->phone ?? null,
                            'is_distributor' => $order->user->isDistributor(),
                        ],
                        'shipping_address' => $order->deliveryAddress ? [
                            'id' => $order->deliveryAddress->id,
                            'address_line_1' => $order->deliveryAddress->address_line_1,
                            'address_line_2' => $order->deliveryAddress->address_line_2,
                            'city' => $order->deliveryAddress->city,
                            'state' => $order->deliveryAddress->state,
                            'postal_code' => $order->deliveryAddress->postal_code,
                            'country' => $order->deliveryAddress->country ?? 'India',
                            'full_address' => $this->formatAddress($order->deliveryAddress),
                        ] : null,
                        'items' => $formattedItems,
                        'returns' => $returns,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $formattedOrders,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOrderDetails(int $id)
    {
        try {
            $order = Order::with([
                'user',
                'billingAddress',
                'deliveryAddress',
                'shippingMethod',
                'invoice',
                'returns',
                'lines.product',
                'lines.product.images',
            ])->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            $formatDate = function ($date) {
                if (!$date) {
                    return null;
                }

                return \Carbon\Carbon::parse($date)->toDateTimeString();
            };

            $items = $order->lines->map(function ($line) use ($formatDate) {
                $product = $line->product;

                $images = $product?->images?->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                    ];
                })->values()->toArray() ?? [];

                $primaryImage = collect($images)
                    ->firstWhere('is_primary', true)['image_url']
                    ?? ($images[0]['image_url'] ?? null);

                return [
                    'line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $product?->name ?? 'Product Not Found',
                    'product_code' => $product?->product_code ?? 'N/A',

                    'quantity' => (int) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'gst_rate' => (float) $line->gst_rate,
                    'gst_amount' => (float) $line->gst_amount,
                    'line_total' => (float) $line->line_total,
                    'commissionable_volume' => (float) $line->commissionable_volume,

                    'delivery_status' => $line->delivery_status ?? 'pending',
                    'return_status' => $line->return_status ?? 'none',
                    'returned_quantity' => (int) ($line->returned_quantity ?? 0),
                    'available_for_return' => $line->getAvailableForReturnAttribute(),
                    'is_returnable' => (bool) ($line->is_returnable ?? true),

                    'timeline' => [
                        'shipped_at' => $formatDate($line->shipped_at),
                        'delivered_at' => $formatDate($line->delivered_at),
                        'return_requested_at' => $formatDate($line->return_requested_at),
                        'return_approved_at' => $formatDate($line->return_approved_at),
                        'return_rejected_at' => $formatDate($line->return_rejected_at),
                        'return_completed_at' => $formatDate($line->return_completed_at),
                    ],

                    'images' => $images,
                    'primary_image' => $primaryImage,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Order details retrieved successfully.',
                'data' => [

                    // Order
                    'id' => $order->id,
                    'order_reference' => $order->order_reference,
                    'order_status' => $order->status,
                    'order_type' => $order->order_type,
                    'order_date' => $formatDate($order->created_at),
                    'confirmed_date' => $formatDate($order->confirmed_at),

                    // Customer
                    'user' => $order->user ? [
                        'id' => $order->user->id,
                        'name' => $order->user->full_name ?? $order->user->name,
                        'email' => $order->user->email,
                        'phone' => $order->user->phone ?? null,
                        'account_type' => $order->user->account_type ?? null,
                        'is_distributor' => $order->user->isDistributor(),
                    ] : null,

                    // Products
                    'items' => $items,

                    // Payment
                    'payment' => [
                        'payment_gateway' => $order->payment_gateway,
                        'gateway_transaction_id' => $order->gateway_transaction_id,
                        'amount_paid' => (float) $order->amount_paid,
                        'payment_status' => $order->amount_paid > 0 ? 'paid' : 'unpaid',
                    ],

                    // Financial Summary
                    'summary' => [
                        'subtotal' => (float) $order->subtotal,
                        'total_gst' => (float) $order->total_gst,
                        'shipping_charge' => (float) $order->shipping_charge,
                        'coupon_code' => $order->coupon_code ?? null,
                        'coupon_discount' => (float) ($order->coupon_discount ?? 0),
                        'coin_redeemed' => (int) ($order->coin_redeemed ?? 0),
                        'coin_redeemed_amount' => (float) ($order->coin_redeemed_amount ?? 0),
                        'total_payable' => (float) $order->total_payable,
                    ],

                    // Tax
                    'tax_breakdown' => !empty($order->tax_breakdown)
                        ? (is_string($order->tax_breakdown)
                            ? json_decode($order->tax_breakdown, true)
                            : $order->tax_breakdown)
                        : [],

                    // Shipping
                    'shipping_method' => $order->shippingMethod ? [
                        'id' => $order->shippingMethod->id,
                        'name' => $order->shippingMethod->name,
                        'code' => $order->shippingMethod->code,
                        'description' => $order->shippingMethod->description,
                        'estimated_days' => $order->shippingMethod->estimated_days,
                    ] : null,

                    // Billing Address
                    'billing_address' => $order->billingAddress ? [
                        'id' => $order->billingAddress->id,
                        'full_name' => $order->billingAddress->full_name,
                        'phone' => $order->billingAddress->phone,
                        'address_line_1' => $order->billingAddress->address_line_1,
                        'address_line_2' => $order->billingAddress->address_line_2,
                        'city' => $order->billingAddress->city,
                        'state' => $order->billingAddress->state,
                        'postal_code' => $order->billingAddress->postal_code,
                        'country' => $order->billingAddress->country ?? 'India',
                        'full_address' => $this->formatAddress($order->billingAddress),
                    ] : null,

                    // Delivery Address
                    'delivery_address' => $order->deliveryAddress ? [
                        'id' => $order->deliveryAddress->id,
                        'full_name' => $order->deliveryAddress->full_name,
                        'phone' => $order->deliveryAddress->phone,
                        'address_line_1' => $order->deliveryAddress->address_line_1,
                        'address_line_2' => $order->deliveryAddress->address_line_2,
                        'city' => $order->deliveryAddress->city,
                        'state' => $order->deliveryAddress->state,
                        'postal_code' => $order->deliveryAddress->postal_code,
                        'country' => $order->deliveryAddress->country ?? 'India',
                        'full_address' => $this->formatAddress($order->deliveryAddress),
                    ] : null,

                    // Invoice
                    'invoice' => $order->invoice ? [
                        'id' => $order->invoice->id,
                        'invoice_number' => $order->invoice->invoice_number,
                        'generated_at' => $formatDate($order->invoice->created_at),
                    ] : null,

                    // Returns
                    'returns' => $order->returns,
                ],
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function statuses(): JsonResponse
    {
        try {
            $result = DB::select("
            SHOW COLUMNS FROM order_lines WHERE Field = 'delivery_status'
        ");

            if (empty($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery status column not found.',
                ], 404);
            }

            $type = $result[0]->Type;

            // Extract enum values
            preg_match('/^enum\((.*)\)$/', $type, $matches);

            $statuses = [];

            if (isset($matches[1])) {
                $statuses = str_getcsv($matches[1], ',', "'");
            }

            return response()->json([
                'success' => true,
                'data' => $statuses,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================
     * OUTBOUND API METHODS (FR-IN-003)
     * For external systems (Commission System)
     * ============================================================
     */

    /**
     * Get orders for external systems.
     * Supports date range, status filter, and pagination.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function externalIndex(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|string|in:pending,confirmed,processing,dispatched,delivered,cancelled,returned',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) $request->input('per_page', 50);

        $query = Order::with(['user', 'lines.product', 'billingAddress', 'deliveryAddress'])
            ->whereIn('status', ['confirmed', 'processing', 'dispatched', 'delivered']);

        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from . ' 00:00:00');
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->items()->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_reference' => $order->order_reference,
                    'user_id' => $order->user_id,
                    'distributor_id' => $order->user->distributor_id ?? null,
                    'account_type' => $order->user->account_type ?? 'customer',
                    'order_type' => $order->order_type,
                    'status' => $order->status,
                    'return_status' => $order->return_status,
                    'total_payable' => (float) $order->total_payable,
                    'subtotal' => (float) $order->subtotal,
                    'total_gst' => (float) $order->total_gst,
                    'shipping_charge' => (float) $order->shipping_charge,
                    'coupon_code' => $order->coupon_code,
                    'coupon_discount' => (float) $order->coupon_discount,
                    'coin_redeemed' => (float) $order->coin_redeemed_amount,
                    'commissionable_volume' => (float) $order->commissionable_volume,
                    'lines' => $order->lines->map(function ($line) {
                        return [
                            'order_line_id' => $line->id,
                            'product_id' => $line->product_id,
                            'product_name' => $line->product->name ?? null,
                            'product_code' => $line->product->product_code ?? null,
                            'quantity' => $line->quantity,
                            'unit_price' => (float) $line->unit_price,
                            'gst_rate' => (float) $line->gst_rate,
                            'gst_amount' => (float) $line->gst_amount,
                            'line_total' => (float) $line->line_total,
                            'commissionable_volume' => (float) $line->commissionable_volume,
                            'return_status' => $line->return_status,
                            'returned_quantity' => $line->returned_quantity,
                        ];
                    }),
                    'delivery_address' => $order->deliveryAddress ? [
                        'recipient_name' => $order->deliveryAddress->recipient_name,
                        'address_line_1' => $order->deliveryAddress->address_line_1,
                        'address_line_2' => $order->deliveryAddress->address_line_2,
                        'city' => $order->deliveryAddress->city,
                        'state' => $order->deliveryAddress->state,
                        'postcode' => $order->deliveryAddress->postcode,
                        'country' => $order->deliveryAddress->country,
                    ] : null,
                    'payment_gateway' => $order->payment_gateway,
                    'gateway_transaction_id' => $order->gateway_transaction_id,
                    'confirmed_at' => $order->confirmed_at?->toISOString(),
                    'delivered_at' => $order->delivered_at?->toISOString(),
                    'created_at' => $order->created_at->toISOString(),
                    'updated_at' => $order->updated_at->toISOString(),
                ];
            }),
            'meta' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Get single order by order_reference for external systems.
     *
     * @param Request $request
     * @param string $orderReference
     * @return JsonResponse
     */
    public function externalShow(Request $request, string $orderReference): JsonResponse
    {
        $order = Order::with(['user', 'lines.product', 'billingAddress', 'deliveryAddress'])
            ->where('order_reference', $orderReference)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    public function orderstatuses(): JsonResponse
    {
        try {
            $result = DB::select("
            SHOW COLUMNS FROM orders WHERE Field = 'status'
        ");

            if (empty($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery status column not found.',
                ], 404);
            }

            $type = $result[0]->Type;

            // Extract enum values
            preg_match('/^enum\((.*)\)$/', $type, $matches);

            $statuses = [];

            if (isset($matches[1])) {
                $statuses = str_getcsv($matches[1], ',', "'");
            }

            return response()->json([
                'success' => true,
                'data' => $statuses,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Dispatch order items or entire order
     */
    public function dispatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_reference' => 'required|string|exists:orders,order_reference',
            'items' => 'nullable|array|min:1',
            'items.*.order_line_id' => 'required|integer|exists:order_lines,id',
            'courier_tracking_number' => 'nullable|string|max:100',
            'courier_company' => 'nullable|string|max:100',
            'delivery_notes' => 'nullable|string|max:500',
            'courier_delivery_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            DB::beginTransaction();

            $order = Order::where('order_reference', $request->order_reference)->firstOrFail();

            // Determine which items to dispatch
            $itemsToDispatch = $this->getItemsToProcess($order, $request->items ?? []);

            if (empty($itemsToDispatch)) {
                throw new Exception('No valid items found to dispatch. Items must be confirmed.');
            }

            $processedItems = [];

            foreach ($itemsToDispatch as $orderLine) {
                $this->validateDispatchable($orderLine);

                $orderLine->update([
                    'delivery_status' => 'dispatched',
                    'dispatched_at' => now(),
                ]);

                $processedItems[] = [
                    'order_line_id' => $orderLine->id,
                    'product_name' => $orderLine->product->name ?? 'Unknown',
                    'status' => 'dispatched'
                ];

                $this->createShippingDetail($order, $orderLine, $request, 'dispatched');
            }

            $order->updateOrderStatus();
            // if ($order->status == 'dispatched') {

            $existingInvoice = $order->invoice;

            if (!$existingInvoice) {
                $this->invoiceService->generateInvoice($order);
            }
            // }

            DB::commit();

            $message = count($processedItems) === $order->lines->count()
                ? 'Entire order dispatched successfully'
                : count($processedItems) . ' item(s) dispatched successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'order_reference' => $order->order_reference,
                'order_status' => $order->fresh()->status,
                'processed_items' => $processedItems,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Ship order items or entire order
     */
    public function ship(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_reference' => 'required|string|exists:orders,order_reference',
            'items' => 'nullable|array|min:1',
            'items.*.order_line_id' => 'required|integer|exists:order_lines,id',
            'courier_tracking_number' => 'nullable|string|max:100',
            'courier_company' => 'nullable|string|max:100',
            'delivery_notes' => 'nullable|string|max:500',
            'courier_delivery_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            DB::beginTransaction();

            $order = Order::where('order_reference', $request->order_reference)->firstOrFail();

            // Determine which items to ship
            $itemsToShip = $this->getItemsToProcess($order, $request->items ?? []);

            if (empty($itemsToShip)) {
                throw new Exception('No valid items found to ship. Items must be dispatched.');
            }

            $processedItems = [];

            foreach ($itemsToShip as $orderLine) {
                $this->validateShippable($orderLine);

                $orderLine->update([
                    'delivery_status' => 'shipped',
                    'shipped_at' => now(),
                ]);

                $processedItems[] = [
                    'order_line_id' => $orderLine->id,
                    'product_name' => $orderLine->product->name ?? 'Unknown',
                    'status' => 'shipped'
                ];

                $this->updateShippingDetail($order, $orderLine, $request, 'shipped');
            }

            $order->updateOrderStatus();

            DB::commit();

            $message = count($processedItems) === $order->lines->count()
                ? 'Entire order shipped successfully'
                : count($processedItems) . ' item(s) shipped successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'order_reference' => $order->order_reference,
                'order_status' => $order->fresh()->status,
                'processed_items' => $processedItems,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Deliver order items or entire order
     */
    // public function deliver(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'order_reference' => 'required|string|exists:orders,order_reference',
    //         'items' => 'nullable|array|min:1',
    //         'items.*.order_line_id' => 'required|integer|exists:order_lines,id',
    //         'delivery_notes' => 'nullable|string|max:500',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['error' => $validator->errors()->first()], 422);
    //     }

    //     try {
    //         DB::beginTransaction();

    //         $order = Order::where('order_reference', $request->order_reference)->firstOrFail();

    //         // Determine which items to deliver
    //         $itemsToDeliver = $this->getItemsToProcess($order, $request->items ?? []);

    //         if (empty($itemsToDeliver)) {
    //             throw new Exception('No valid items found to deliver. Items must be shipped or dispatched.');
    //         }

    //         $processedItems = [];

    //         foreach ($itemsToDeliver as $orderLine) {
    //             $this->validateDeliverable($orderLine);

    //             $orderLine->update([
    //                 'delivery_status' => 'delivered',
    //                 'delivered_at' => now(),
    //             ]);

    //             $processedItems[] = [
    //                 'order_line_id' => $orderLine->id,
    //                 'product_name' => $orderLine->product->name ?? 'Unknown',
    //                 'status' => 'delivered'
    //             ];

    //             $this->updateShippingDetail($order, $orderLine, $request, 'delivered');
    //         }

    //         $order->updateOrderStatus();

    //         DB::commit();

    //         $message = count($processedItems) === $order->lines->count()
    //             ? 'Entire order delivered successfully'
    //             : count($processedItems) . ' item(s) delivered successfully';

    //         return response()->json([
    //             'success' => true,
    //             'message' => $message,
    //             'order_reference' => $order->order_reference,
    //             'order_status' => $order->fresh()->status,
    //             'processed_items' => $processedItems,
    //         ]);
    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['error' => $e->getMessage()], 400);
    //     }
    // }
    public function deliver(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_reference' => 'required|string|exists:orders,order_reference',
            'items' => 'nullable|array|min:1',
            'items.*.order_line_id' => 'required|integer|exists:order_lines,id',
            'delivery_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            DB::beginTransaction();

            $order = Order::where('order_reference', $request->order_reference)
                ->with('lines.product')
                ->firstOrFail();

            // Determine which items to deliver
            $itemsToDeliver = $this->getItemsToProcess($order, $request->items ?? []);

            if (empty($itemsToDeliver)) {
                throw new Exception('No valid items found to deliver. Items must be shipped or dispatched.');
            }

            $processedItems = [];

            foreach ($itemsToDeliver as $orderLine) {
                $this->validateDeliverable($orderLine);

                $orderLine->update([
                    'delivery_status' => 'delivered',
                    'delivered_at' => now(),
                ]);

                $processedItems[] = [
                    'order_line_id' => $orderLine->id,
                    'product_name' => $orderLine->product->name ?? 'Unknown',
                    'status' => 'delivered'
                ];

                $this->updateShippingDetail(
                    $order,
                    $orderLine,
                    $request,
                    'delivered'
                );
            }

            // Update main order status
            $order->updateOrderStatus();

            // Refresh order
            $order = $order->fresh(['lines']);

            /*
        |--------------------------------------------------------------------------
        | Generate Invoice
        |--------------------------------------------------------------------------
        | Generate invoice only when entire order is delivered
        */
            // if ($order->status == 'delivered') {

            //     $existingInvoice = $order->invoice;

            //     if (!$existingInvoice) {
            //         $this->invoiceService->generateInvoice($order);
            //     }
            // }

            DB::commit();

            $message = count($processedItems) === $order->lines->count()
                ? 'Entire order delivered successfully'
                : count($processedItems) . ' item(s) delivered successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'order_reference' => $order->order_reference,
                'order_status' => $order->status,
                'processed_items' => $processedItems,
            ]);
        } catch (Exception $e) {

            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get items to process based on request
     * If items array is empty, process entire order
     * If items array is provided, process only those items
     */
    private function getItemsToProcess(Order $order, array $requestedItems = []): array
    {
        // If no specific items requested, process entire order
        if (empty($requestedItems)) {
            return $order->lines->all();
        }

        // Process only requested items
        $lineIds = array_column($requestedItems, 'order_line_id');
        $processedItems = [];

        foreach ($lineIds as $lineId) {
            $orderLine = $order->lines->firstWhere('id', $lineId);
            if ($orderLine) {
                $processedItems[] = $orderLine;
            }
        }

        return $processedItems;
    }

    /**
     * Get order shipping details
     */
    public function getShippingDetails($orderReference)
    {
        $order = Order::where('order_reference', $orderReference)->firstOrFail();

        $shippingDetails = OrderShippingDetail::where('order_id', $order->id)
            ->with('orderLine.product')
            ->get();

        return response()->json([
            'order_reference' => $order->order_reference,
            'order_status' => $order->status,
            'shipping_details' => $shippingDetails->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'order_line_id' => $detail->order_line_id,
                    'product_name' => $detail->orderLine->product->name ?? 'Unknown',
                    'courier_company' => $detail->courier_company,
                    'courier_tracking_number' => $detail->courier_tracking_number,
                    'delivery_notes' => $detail->delivery_notes,
                    'courier_delivery_date' => $detail->courier_delivery_date,
                    'status' => $detail->status,
                    'created_at' => $detail->created_at,
                    'updated_at' => $detail->updated_at,
                ];
            })
        ]);
    }

    // Validation Methods
    private function validateDispatchable(OrderLine $orderLine)
    {
        if ($orderLine->delivery_status !== 'confirmed') {
            throw new Exception(
                "Item '{$orderLine->product->name}' must be confirmed before dispatch. Current status: {$orderLine->delivery_status}"
            );
        }

        if (in_array($orderLine->delivery_status, ['dispatched', 'shipped', 'delivered'])) {
            throw new Exception(
                "Item '{$orderLine->product->name}' has already been dispatched/shipped/delivered."
            );
        }

        if ($orderLine->delivery_status === 'cancelled') {
            throw new Exception(
                "Item '{$orderLine->product->name}' is cancelled and cannot be dispatched."
            );
        }
    }

    private function validateShippable(OrderLine $orderLine)
    {
        if ($orderLine->delivery_status !== 'dispatched') {
            throw new Exception(
                "Item '{$orderLine->product->name}' must be dispatched before shipping. Current status: {$orderLine->delivery_status}"
            );
        }

        if ($orderLine->delivery_status === 'delivered') {
            throw new Exception(
                "Item '{$orderLine->product->name}' is already delivered."
            );
        }
    }

    private function validateDeliverable(OrderLine $orderLine)
    {
        if (!in_array($orderLine->delivery_status, ['dispatched', 'shipped'])) {
            throw new Exception(
                "Item '{$orderLine->product->name}' must be dispatched or shipped before delivery. Current status: {$orderLine->delivery_status}"
            );
        }

        if ($orderLine->delivery_status === 'delivered') {
            throw new Exception(
                "Item '{$orderLine->product->name}' is already delivered."
            );
        }
    }

    // Helper Methods
    private function createShippingDetail(Order $order, OrderLine $orderLine, Request $request, string $status)
    {
        return OrderShippingDetail::create([
            'order_id' => $order->id,
            'order_line_id' => $orderLine->id,
            'courier_tracking_number' => $request->courier_tracking_number,
            'courier_company' => $request->courier_company,
            'delivery_notes' => $request->delivery_notes,
            'courier_delivery_date' => $request->courier_delivery_date,
            'status' => $status,
        ]);
    }

    private function updateShippingDetail(Order $order, OrderLine $orderLine, Request $request, string $status)
    {
        $detail = OrderShippingDetail::where('order_id', $order->id)
            ->where('order_line_id', $orderLine->id)
            ->first();

        if ($detail) {
            $detail->update([
                'courier_tracking_number' => $request->courier_tracking_number ?? $detail->courier_tracking_number,
                'courier_company' => $request->courier_company ?? $detail->courier_company,
                'delivery_notes' => $request->delivery_notes ?? $detail->delivery_notes,
                'courier_delivery_date' => $request->courier_delivery_date ?? $detail->courier_delivery_date,
                'status' => $status,
            ]);
        } else {
            $this->createShippingDetail($order, $orderLine, $request, $status);
        }
    }
}
