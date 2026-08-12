<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    protected CheckoutService $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * FR-CO-003: Get order summary with itemized GST
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id,user_id,' . auth()->id(),
        ]);

        try {
            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $validated['address_id']
            );

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
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