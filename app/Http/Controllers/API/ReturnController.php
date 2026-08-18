<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Exception;

class ReturnController extends Controller
{
    protected ReturnService $returnService;

    public function __construct(ReturnService $returnService)
    {
        $this->returnService = $returnService;
    }

    /**
     * Get return eligibility for an order
     * GET /api/returns/eligibility
     */
    public function eligibility(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_reference' => 'required|string|exists:orders,order_reference',
            ]);

            $result = $this->returnService->getReturnEligibility(
                auth()->id(),
                $validated['order_reference']
            );

            return response()->json([
                'success' => true,
                'data' => $result,
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
     * Initiate a return request
     * POST /api/returns/initiate
     */
    public function initiate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_reference' => 'required|string|exists:orders,order_reference',
                'items' => 'required|array|min:1',
                'items.*.order_line_id' => 'required|exists:order_lines,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.reason' => 'nullable|string|max:500',
                'return_reason' => 'nullable|string|max:1000',
            ]);

            $result = $this->returnService->initiateReturn(
                auth()->id(),
                $validated
            );

            return response()->json($result);
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
     * Get user's return history
     * GET /api/returns/my-returns
     */
    public function myReturns(Request $request): JsonResponse
    {
        try {
            $returns = $this->returnService->getUserReturns(auth()->id());

            return response()->json([
                'success' => true,
                'data' => $returns,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get single return details for user
     * GET /api/returns/{id}
     */
    public function show(int $returnId): JsonResponse
    {
        try {
            $return = $this->returnService->getUserReturn(auth()->id(), $returnId);

            if (!$return) {
                return response()->json([
                    'success' => false,
                    'message' => 'Return not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $return,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // =========================================================================
    // ADMIN ROUTES (protected by admin middleware)
    // =========================================================================

    /**
     * Admin: Get all return requests
     * GET /api/admin/returns
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status');
            $result = $this->returnService->getReturnsForAdmin($status);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Admin: Get single return details
     * GET /api/admin/returns/{id}
     */
    public function adminShow(int $returnId): JsonResponse
    {
        try {
            $return = $this->returnService->getReturnForAdmin($returnId);

            return response()->json([
                'success' => true,
                'data' => $return,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Admin: Approve return request
     * POST /api/admin/returns/{id}/approve
     */
    public function adminApprove(Request $request, int $returnId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'admin_notes' => 'nullable|string|max:500',
            ]);

            $result = $this->returnService->approveReturn(
                $returnId,
                auth()->id(),
                $validated['admin_notes'] ?? null
            );

            return response()->json($result);
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
     * Admin: Reject return request
     * POST /api/admin/returns/{id}/reject
     */
    public function adminReject(Request $request, int $returnId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $result = $this->returnService->rejectReturn(
                $returnId,
                auth()->id(),
                $validated['rejection_reason']
            );

            return response()->json($result);
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
     * Admin: Mark return as received
     * POST /api/admin/returns/{id}/received
     */
    public function adminMarkReceived(int $returnId): JsonResponse
    {
        try {
            $result = $this->returnService->markReturnReceived($returnId);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Admin: Complete return
     * POST /api/admin/returns/{id}/complete
     */
    public function adminComplete(int $returnId): JsonResponse
    {
        try {
            $result = $this->returnService->completeReturn($returnId);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
