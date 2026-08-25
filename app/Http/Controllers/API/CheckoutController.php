<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

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
    // public function summary(Request $request): JsonResponse
    // {
    //     try {
    //         $validated = $request->validate([
    //             'address_id' => 'nullable|exists:addresses,id,user_id,' . auth()->id(),
    //             'coupon_code' => 'nullable|string|max:50',
    //             'shipping_method_id' => 'nullable|exists:shipping_methods,id',
    //         ]);

    //         // Get or find address
    //         $addressId = $validated['address_id'] ?? null;

    //         if (!$addressId) {
    //             $defaultAddress = Address::where('user_id', auth()->id())
    //                 ->where(function ($query) {
    //                     $query->where('is_default', true)
    //                         ->orWhere('is_billing', true);
    //                 })
    //                 ->first();

    //             if ($defaultAddress) {
    //                 $addressId = $defaultAddress->id;
    //             } else {
    //                 return response()->json([
    //                     'success' => false,
    //                     'message' => 'No delivery address found. Please add an address first.',
    //                     'data' => [
    //                         'needs_address' => true,
    //                         'has_coupon' => !empty($validated['coupon_code']),
    //                         'coupon_applied' => false,
    //                     ]
    //                 ], 400);
    //             }
    //         }

    //         $summary = $this->checkoutService->calculateSummary(
    //             auth()->id(),
    //             $addressId,
    //             $validated['coupon_code'] ?? null,
    //             $validated['shipping_method_id'] ?? null
    //         );

    //         return response()->json([
    //             'success' => true,
    //             'data' => $summary,
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

    // public function summary(Request $request): JsonResponse
    // {
    //     try {
    //         $validated = $request->validate([
    //             'address_id' => 'nullable|exists:addresses,id,user_id,' . auth()->id(),
    //             'coupon_code' => 'nullable|string|max:50',
    //             'shipping_method_id' => 'nullable|exists:shipping_methods,id',
    //             'coins' => 'nullable|integer|min:0',
    //         ]);

    //         // Get or find address
    //         $addressId = $validated['address_id'] ?? null;

    //         if (!$addressId) {
    //             $defaultAddress = Address::where('user_id', auth()->id())
    //                 ->where(function ($query) {
    //                     $query->where('is_default', true)
    //                         ->orWhere('is_billing', true);
    //                 })
    //                 ->first();

    //             if ($defaultAddress) {
    //                 $addressId = $defaultAddress->id;
    //             } else {
    //                 return response()->json([
    //                     'success' => false,
    //                     'message' => 'No delivery address found. Please add an address first.',
    //                     'data' => [
    //                         'needs_address' => true,
    //                         'has_coupon' => !empty($validated['coupon_code']),
    //                         'coupon_applied' => false,
    //                     ]
    //                 ], 400);
    //             }
    //         }

    //         $summary = $this->checkoutService->calculateSummary(
    //             auth()->id(),
    //             $addressId,
    //             $validated['coupon_code'] ?? null,
    //             $validated['shipping_method_id'] ?? null,
    //             $validated['coins'] ?? null
    //         );

    //         return response()->json([
    //             'success' => true,
    //             'data' => $summary,
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
                'shipping_method_id' => 'nullable|exists:shipping_methods,id',
                'coins' => 'nullable|integer|min:0',

                // Buy Now
                'product_id' => 'nullable|exists:products,id',
                'quantity' => 'nullable|integer|min:1',
            ]);

            // product_id and quantity must come together
            if (
                isset($validated['product_id']) &&
                !isset($validated['quantity'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quantity is required for Buy Now.',
                ], 422);
            }

            if (
                isset($validated['quantity']) &&
                !isset($validated['product_id'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product ID is required for Buy Now.',
                ], 422);
            }

            // Get or find address
            $addressId = $validated['address_id'] ?? null;

            // if (!$addressId) {
            //     $defaultAddress = Address::where('user_id', auth()->id())
            //         ->where(function ($query) {
            //             $query->where('is_default', true)
            //                 ->orWhere('is_billing', true);
            //         })
            //         ->first();

            //     if ($defaultAddress) {
            //         $addressId = $defaultAddress->id;
            //     } else {
            //         return response()->json([
            //             'success' => false,
            //             'message' => 'No delivery address found. Please add an address first.',
            //             'data' => [
            //                 'needs_address' => true,
            //                 'has_coupon' => !empty($validated['coupon_code']),
            //                 'coupon_applied' => false,
            //             ]
            //         ], 400);
            //     }
            // }

            // Buy Now if product_id is provided
            $isBuyNow = isset($validated['product_id']);

            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $addressId,
                $validated['coupon_code'] ?? null,
                $validated['shipping_method_id'] ?? null,
                $validated['coins'] ?? null,

                // Buy Now parameters
                $validated['product_id'] ?? null,
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
    public function applyCoins(Request $request): JsonResponse
    {
        if (!auth()->user()->isDistributor()) {
            return response()->json([
                'success' => false,
                'message' => 'Only distributors can redeem coins.',
            ], 403);
        }

        $validated = $request->validate([
            'coins' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->checkoutService->applyCoins(
                auth()->id(),
                $validated['coins']
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

    /**
     * FR-CO-005: Place order and initiate Razorpay payment
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'grand_total' => 'required|numeric|min:0',
            'payment_gateway' => 'nullable|in:razorpay',

            'redemption_id' => 'nullable|exists:coin_redemptions,id,user_id,' . auth()->id() . ',status,authorized',

            // Change from summary_data to summary
            'summary_data' => 'required|array',
            'summary_data.subtotal' => 'sometimes|numeric|min:0',
            'summary_data.total_tax' => 'sometimes|numeric|min:0',
            'summary_data.coupon_code' => 'nullable|string|max:50',
            'summary_data.coupon_discount' => 'nullable|numeric|min:0',
            'summary_data.shipping_charge' => 'nullable|numeric|min:0',
            'summary_data.shipping_method_id' => 'nullable|exists:shipping_methods,id',
            'summary_data.coin_redeemed' => 'nullable|numeric|min:0',
            'summary_data.amount_redeemed' => 'nullable|numeric|min:0',
            'summary_data.net_subtotal' => 'nullable|numeric|min:0',

            // Tax breakdown validation
            'summary_data.tax_breakdown' => 'sometimes|array',
            'summary_data.tax_breakdown.*.product_name' => 'required_with:summary_data.tax_breakdown|string|max:255',
            'summary_data.tax_breakdown.*.tax_category' => 'required_with:summary_data.tax_breakdown|string|max:100',
            'summary_data.tax_breakdown.*.rate' => 'required_with:summary_data.tax_breakdown|numeric|min:0',
        ]);
        try {
            $result = $this->checkoutService->placeOrder(
                auth()->id(),
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
}