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
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $history = $this->checkoutService->getOrderHistory(
                auth()->id(),
                $request->only(['status', 'from_date', 'to_date', 'order_type', 'per_page'])
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
     */
    public function show(string $orderReference): JsonResponse
    {
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
     * FR-CO-010: Cancel order
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