<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    protected CheckoutService $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * Get cart summary
     */
    //    public function summary(Request $request)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'address_id' => 'nullable|exists:addresses,id,user_id,' . auth()->id(),
    //             'coupon_code' => 'nullable|string|max:50',
    //             'shipping_method_id' => 'nullable|exists:shipping_methods,id',
    //             'coins' => 'nullable|integer|min:0',

    //             // Buy Now
    //             'product_id' => 'nullable|exists:products,id',
    //             'variant_id' => 'nullable|exists:product_variants,id',
    //             'quantity' => 'nullable|integer|min:1',
    //         ]);

    //         // Buy Now validation
    //         $hasProduct = isset($validated['product_id']);
    //         $hasVariant = isset($validated['variant_id']);
    //         $hasQuantity = isset($validated['quantity']);

    //         // If product_id or variant_id is provided, quantity is required
    //         if (($hasProduct || $hasVariant) && !$hasQuantity) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Quantity is required for Buy Now.',
    //             ], 422);
    //         }

    //         // Product and variant cannot be provided together
    //         if ($hasProduct && $hasVariant) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'You cannot provide both product_id and variant_id.',
    //             ], 422);
    //         }

    //         // Get or find address
    //         $addressId = $validated['address_id'] ?? null;

    //         // Buy Now if product_id or variant_id is provided
    //         $isBuyNow = $hasProduct || $hasVariant;

    //         $summary = $this->checkoutService->calculateSummary(
    //             auth()->id(),
    //             $addressId,
    //             $validated['coupon_code'] ?? null,
    //             $validated['shipping_method_id'] ?? null,
    //             $validated['coins'] ?? null,

    //             // Buy Now parameters
    //             $validated['product_id'] ?? null,
    //             $validated['variant_id'] ?? null,
    //             $validated['quantity'] ?? null
    //         );

    //         return response()->json([
    //             'success' => true,

    //             'data' => array_merge($summary, [
    //                 'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
    //             ]),
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Invalid parameters provided',
    //             'errors' => $e->errors(),
    //         ], 422);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 400);
    //     }
    // }
    public function summary(Request $request)
    {
        try {
            $validated = $request->validate([
                'address_id' => 'nullable|exists:addresses,id,user_id,' . auth()->id(),
                'coupon_code' => 'nullable|string|max:50',
                'coins' => 'nullable|integer|min:0',

                // Buy Now
                'product_id' => 'nullable|exists:products,id',
                'variant_id' => 'nullable|exists:product_variants,id',
                'quantity' => 'nullable|integer|min:1',
            ]);

            // Buy Now validation
            $hasProduct = isset($validated['product_id']);
            $hasVariant = isset($validated['variant_id']);
            $hasQuantity = isset($validated['quantity']);

            // If product_id or variant_id is provided, quantity is required
            if (($hasProduct || $hasVariant) && !$hasQuantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quantity is required for Buy Now.',
                ], 422);
            }

            // Product and variant cannot be provided together
            if ($hasProduct && $hasVariant) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot provide both product_id and variant_id.',
                ], 422);
            }

            // Get or find address
            $addressId = $validated['address_id'] ?? null;

            // Buy Now if product_id or variant_id is provided
            $isBuyNow = $hasProduct || $hasVariant;

            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $addressId,
                $validated['coupon_code'] ?? null,
                null,
                $validated['coins'] ?? null,
                $validated['product_id'] ?? null,
                $validated['variant_id'] ?? null,
                $validated['quantity'] ?? null
            );

            return response()->json([
                'success' => true,

                'data' => array_merge($summary, [
                    'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
                ]),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid parameters provided',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Apply coupon to cart
     */
    public function applyCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'coupon_code' => 'required|string|max:50',
            'coins' => 'nullable|integer|min:0',
        ]);

        try {
            $summary = $this->checkoutService->applyCoupon(
                auth()->id(),
                $validated['address_id'],
                $validated['coupon_code'],
                $validated['coins'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Coupon applied successfully',
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Apply shipping method
     */
    public function applyShipping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'shipping_method_id' => 'required|exists:shipping_methods,id',
            'coupon_code' => 'nullable|string|max:50',
            'coins' => 'nullable|integer|min:0',
        ]);

        try {
            $summary = $this->checkoutService->applyShipping(
                auth()->id(),
                $validated['address_id'],
                $validated['shipping_method_id'],
                $validated['coupon_code'] ?? null,
                $validated['coins'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Shipping method applied successfully',
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Remove coupon
     */
    public function removeCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'shipping_method_id' => 'nullable|exists:shipping_methods,id',
            'coins' => 'nullable|integer|min:0',
        ]);

        try {
            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $validated['address_id'],
                null, // Remove coupon
                $validated['shipping_method_id'] ?? null,
                $validated['coins'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Coupon removed successfully',
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * FR-CO-004: Apply coin redemption (distributor only)
     */
    // public function applyCoins(Request $request): JsonResponse
    // {
    //     if (!auth()->user()->isDistributor()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Only distributors can redeem coins.',
    //         ], 403);
    //     }

    //     $validated = $request->validate([
    //         'coins' => 'required|integer|min:1',
    //     ]);

    //     try {
    //         $result = $this->checkoutService->applyCoins(
    //             auth()->id(),
    //             $validated['coins']
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

    public function applyCoins(Request $request): JsonResponse
    {
        if (!auth()->user()->isDistributor() || empty(auth()->user()->distributor_id)) {
            return response()->json(['success' => false, 'message' => 'Only distributors can redeem coins.'], 403);
        }

        $validated = $request->validate([
            'coins' => 'required|integer|min:1',
            'point_type' => 'required|in:yellow_bal,blue_bal', // 🔥 Frontend se bhejna compulsory hai
        ]);

        try {
            $result = $this->checkoutService->applyCoins(auth()->id(), (int) $validated['coins'], $validated['point_type']);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * FR-CO-005: Place order and initiate Razorpay payment
     */

    // public function placeOrder(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
    //         'grand_total' => 'required|numeric|min:0',
    //         'payment_gateway' => 'required|in:razorpay,hdfc_sg,hdfc_ie',

    //         'redemption_id' => 'nullable|exists:coin_redemptions,id,user_id,' . auth()->id() . ',status,authorized',

    //         'checkout_type' => 'nullable|in:cart,buy_now',

    //         // Buy Now items
    //         'summary_data' => 'required|array',
    //         'summary_data.items' => 'required_if:checkout_type,buy_now|array|min:1',
    //         'summary_data.items.*.product_id' => 'required_if:checkout_type,buy_now|integer|exists:products,id',
    //         'summary_data.items.*.quantity' => 'required_if:checkout_type,buy_now|integer|min:1',
    //         'summary_data.items.*.variant_id' => 'nullable|integer|exists:product_variants,id',

    //         'summary_data.subtotal' => 'sometimes|numeric|min:0',
    //         'summary_data.total_tax' => 'sometimes|numeric|min:0',
    //         'summary_data.coupon_code' => 'nullable|string|max:50',
    //         'summary_data.coupon_discount' => 'nullable|numeric|min:0',
    //         'summary_data.shipping_charge' => 'nullable|numeric|min:0',
    //         'summary_data.shipping_method_id' => 'nullable|exists:shipping_methods,id',
    //         'summary_data.coin_redeemed' => 'nullable|numeric|min:0',
    //         'summary_data.amount_redeemed' => 'nullable|numeric|min:0',
    //         'summary_data.net_subtotal' => 'nullable|numeric|min:0',

    //         'summary_data.tax_breakdown' => 'sometimes|array',
    //         'summary_data.tax_breakdown.*.product_name' => 'required_with:summary_data.tax_breakdown|string|max:255',
    //         'summary_data.tax_breakdown.*.tax_category' => 'required_with:summary_data.tax_breakdown|string|max:100',
    //         'summary_data.tax_breakdown.*.rate' => 'required_with:summary_data.tax_breakdown|min:0',
    //     ]);

    //     try {
    //         $result = $this->checkoutService->placeOrder(
    //             Auth::user()->id,
    //             $validated
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
    public function placeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'grand_total' => 'required|numeric|min:0',
            'payment_gateway' => 'required|in:razorpay,hdfc_sg,hdfc_ie',

            'redemption_id' => 'nullable|exists:coin_redemptions,id,user_id,' . auth()->id() . ',status,authorized',

            'checkout_type' => 'nullable|in:cart,buy_now',

            // Buy Now items
            'summary_data' => 'required|array',
            'summary_data.items' => 'required_if:checkout_type,buy_now|array|min:1',
            'summary_data.items.*.product_id' => 'required_if:checkout_type,buy_now|integer|exists:products,id',
            'summary_data.items.*.quantity' => 'required_if:checkout_type,buy_now|integer|min:1',
            'summary_data.items.*.variant_id' => 'nullable|integer|exists:product_variants,id',

            'summary_data.subtotal' => 'sometimes|numeric|min:0',
            'summary_data.total_tax' => 'sometimes|numeric|min:0',
            'summary_data.coupon_code' => 'nullable|string|max:50',
            'summary_data.coupon_discount' => 'nullable|numeric|min:0',
            'summary_data.shipping_charge' => 'nullable|numeric|min:0',
            'summary_data.shipping_method_id' => 'nullable|exists:shipping_methods,id',
            'summary_data.coin_redeemed' => 'nullable|numeric|min:0',
            'summary_data.amount_redeemed' => 'nullable|numeric|min:0',
            'summary_data.net_subtotal' => 'nullable|numeric|min:0',

            'summary_data.tax_breakdown' => 'sometimes|array',
            'summary_data.tax_breakdown.*.product_name' => 'required_with:summary_data.tax_breakdown|string|max:255',
            'summary_data.tax_breakdown.*.tax_category' => 'required_with:summary_data.tax_breakdown|string|max:100',
            'summary_data.tax_breakdown.*.rate' => 'required_with:summary_data.tax_breakdown|min:0',
        ]);

        try {
            $result = $this->checkoutService->placeOrder(
                Auth::user()->id,
                $validated
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

    public function repayOrder(string $orderReference): JsonResponse
    {
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', auth()->id())
            ->with(['lines', 'user'])
            ->firstOrFail();

        // ----------------------------------------------------------
        // 1. Basic guards
        // ----------------------------------------------------------
        if ((float) $order->amount_paid > 0 || $order->status === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.',
            ], 422);
        }

        if (!in_array($order->status, ['pending', 'failed'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Order cannot be repaid in status: {$order->status}",
            ], 422);
        }

        // ----------------------------------------------------------
        // 2. 30-min window check (created_at + 30 min)
        // ----------------------------------------------------------
        $deadline = $order->created_at?->copy()->addMinutes(30);

        if (!$deadline || now()->greaterThanOrEqualTo($deadline)) {
            // Auto mark failed agar abhi pending hai
            if ($order->status === 'pending') {
                DB::transaction(function () use ($order) {
                    $order->update(['status' => 'failed']);
                    $order->lines()->update(['delivery_status' => 'failed']);
                });
            }

            return response()->json([
                'success' => false,
                'message' => 'Repayment window has expired. Please place a new order.',
            ], 410);
        }

        // ----------------------------------------------------------
        // 3. Gateway validation (jaisa placeOrder me tha)
        // ----------------------------------------------------------
        $gateway = $order->payment_gateway;

        if (!$gateway || !in_array($gateway, ['razorpay', 'hdfc_sg', 'hdfc_ie'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment gateway not available for this order.',
            ], 422);
        }

        try {
            // ------------------------------------------------------
            // 4. Re-create gateway session (same shape as placeOrder)
            // ------------------------------------------------------
            $gatewayPayload = [];

            if ($gateway === 'razorpay') {
                $razorpayService = app(\App\Services\PaymentGateway\RazorpayService::class);
                $razorpayOrder   = $razorpayService->createOrder($order);
                $gatewayPayload  = [
                    'razorpay_order_id' => $razorpayOrder['id'],
                    'razorpay_key'      => config('services.razorpay.key_id'),
                ];
            } elseif (in_array($gateway, ['hdfc_sg', 'hdfc_ie'], true)) {
                $configKey = $gateway === 'hdfc_sg' ? 'sg' : 'ie';
                $config    = config("services.hdfc.{$configKey}");

                if (!$config || empty($config['api_key'])) {
                    throw new \Exception("HDFC merchant [{$gateway}] is not configured.");
                }

                $hdfcService = new \App\Services\PaymentGateway\HdfcService($config, $gateway);
                $hdfcOrder   = $hdfcService->createOrder($order);

                $gatewayPayload = [
                    'hdfc_order_id'     => $hdfcOrder['id'],
                    'hdfc_payment_link' => $hdfcOrder['payment_link'],
                    'hdfc_sdk_payload'  => $hdfcOrder['sdk_payload'],
                    'hdfc_merchant_id'  => $config['merchant_id'],
                    'hdfc_gateway_key'  => $gateway,
                ];
            }

            // ------------------------------------------------------
            // 5. Re-open order + lines (failed -> pending)
            //    NOTE: created_at nahi badal raha, isliye 30-min
            //          window same rehti hai (jo original thi).
            //    Agar repay ke baad naya window chahiye to yahan
            //    'created_at' => now() karna padega — par usse
            //    order ka "placed on" date badal jayega, isliye
            //    maine nahi kiya. Aapke requirement ke hisaab se
            //    repay ka window same 30-min wala hi rahega.
            // ------------------------------------------------------
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'pending']);
                $order->lines()->update(['delivery_status' => 'pending']);
            });

            // ------------------------------------------------------
            // 6. Same response shape as placeOrder()
            // ------------------------------------------------------
            return response()->json([
                'success' => true,
                'message' => 'Repayment session created.',
                'data'    => array_merge([
                    'order_id'           => $order->id,
                    'order_reference'    => $order->order_reference,
                    'total_items'        => $order->lines()->count(),
                    'total_amount'       => (float) $order->total_payable,
                    'status'             => 'pending',
                    'checkout_type'      => $order->checkout_type ?? 'cart',
                    'gateway'            => $gateway,
                    'payment_expires_at' => $deadline->toDateTimeString(),
                    'tax_split'          => [
                        'delivery_state' => strtolower(optional($order->deliveryAddress)->state ?? ''),
                        'supplier_state' => strtolower(config('app.supplier_state', 'Maharashtra')),
                        'total_cgst'     => (float) $order->total_cgst,
                        'total_sgst'     => (float) $order->total_sgst,
                        'total_igst'     => (float) $order->total_igst,
                    ],
                ], $gatewayPayload),
            ]);
        } catch (\Exception $e) {
            Log::error('repayOrder failed', [
                'order_reference' => $orderReference,
                'gateway'         => $gateway,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}