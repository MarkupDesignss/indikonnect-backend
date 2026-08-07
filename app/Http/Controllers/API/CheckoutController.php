<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutSummaryRequest;
use App\Http\Requests\ApplyCoinsRequest;
use App\Http\Requests\PlaceOrderRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    protected $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * FR-CO-003: Get order summary with GST calculation
     */
    public function summary(CheckoutSummaryRequest $request): JsonResponse
    {
        try {
            $summary = $this->checkoutService->calculateSummary(
                auth()->id(),
                $request->address_id
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
     * FR-CO-004: Apply coin redemption at checkout
     */
    public function applyCoins(ApplyCoinsRequest $request): JsonResponse
    {
        try {
            $result = $this->checkoutService->applyCoins(
                auth()->id(),
                $request->coins
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
     * FR-CO-005: Place order and initiate payment
     */
    public function placeOrder(PlaceOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->checkoutService->placeOrder(
                auth()->id(),
                $request->validated()
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