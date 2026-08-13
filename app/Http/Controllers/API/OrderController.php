<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    protected $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * FR-CO-008: Get order history
     * GET /api/order/history
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,confirmed,processing,dispatched,delivered,cancelled,returned',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'order_type' => 'nullable|in:retail,distributor',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $history = $this->checkoutService->getOrderHistory(
                auth()->id(),
                $validated
            );

            return response()->json([
                'success' => true,
                'data' => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * FR-CO-008: Get order detail
     * GET /api/order/{orderReference}
     */
    public function show(string $orderReference): JsonResponse
    {
        // No additional validation needed; the service will handle not found

        try {
            $order = $this->checkoutService->getOrderDetail(
                auth()->id(),
                $orderReference
            );

            return response()->json([
                'success' => true,
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * FR-CO-010: Cancel order (before dispatch)
     * POST /api/order/{orderReference}/cancel
     */
    public function cancel(string $orderReference): JsonResponse
    {
        try {
            $result = $this->checkoutService->cancelOrder(
                auth()->id(),
                $orderReference
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
            'payment_gateway' => $order->payment_gateway,
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

    public function getOrder()
    {
        try {
            $orders = Order::with([
                'user',
                'lines.product',
                'lines.product.images',
                'billingAddress',
                'deliveryAddress',
                'invoice'
            ])
                ->where('user_id', auth()->id())
                ->latest()
                ->get();

            $formattedOrders = $orders->map(function ($order) {
                return $this->formatOrderDetails($order);
            });

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

    public function statuses(): JsonResponse
    {
        try {
            $result = DB::select("
            SHOW COLUMNS FROM orders WHERE Field = 'status'
        ");

            if (empty($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status column not found.',
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
}
