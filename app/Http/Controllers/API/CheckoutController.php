<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'coupon_code' => 'nullable|string|max:50',
            'shipping_method_id' => 'nullable|exists:shipping_methods,id',
        ]);

        try {
            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $validated['address_id'],
                $validated['coupon_code'] ?? null,
                $validated['shipping_method_id'] ?? null
            );

            return response()->json([
                'success' => true,
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
     * Apply coupon to cart
     */
    public function applyCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
            'coupon_code' => 'required|string|max:50',
        ]);

        try {
            $summary = $this->checkoutService->applyCoupon(
                auth()->id(),
                $validated['address_id'],
                $validated['coupon_code']
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
        ]);

        try {
            $summary = $this->checkoutService->applyShipping(
                auth()->id(),
                $validated['address_id'],
                $validated['shipping_method_id'],
                $validated['coupon_code'] ?? null
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
        ]);

        try {
            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $validated['address_id'],
                null, // Remove coupon
                $validated['shipping_method_id'] ?? null
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
            'redemption_id' => 'nullable|exists:coin_redemptions,id,user_id,' . auth()->id() . ',status,authorized',
            'payment_gateway' => 'nullable|in:razorpay',
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