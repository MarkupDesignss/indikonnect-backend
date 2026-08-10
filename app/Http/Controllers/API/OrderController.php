<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
}