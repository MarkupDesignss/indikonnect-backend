<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Exception;
use Illuminate\Support\Facades\Log;

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
    // public function initiate(Request $request): JsonResponse
    // {
    //     try {
    //         $validated = $request->validate([
    //             'order_reference' => 'required|string|exists:orders,order_reference',
    //             'items' => 'required|array|min:1',
    //             'items.*.order_line_id' => 'required|exists:order_lines,id',
    //             'items.*.quantity' => 'required|integer|min:1',
    //             'items.*.reason' => 'nullable|string|max:500',
    //             'return_reason' => 'nullable|string|max:1000',
    //         ]);

    //         $result = $this->returnService->initiateReturn(
    //             auth()->id(),
    //             $validated
    //         );

    //         return response()->json($result);
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

    /**
     * Initiate a return request.
     */
    public function initiate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_reference' => [
                    'required',
                    'string',
                    'exists:orders,order_reference',
                ],

                'items' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'items.*.order_line_id' => [
                    'required',
                    'integer',
                    'exists:order_lines,id',
                ],

                'items.*.quantity' => [
                    'required',
                    'integer',
                    'min:1',
                ],

                'items.*.reason' => [
                    'nullable',
                    'string',
                    'max:500',
                ],

                /*
                 * Item-specific images
                 */
                'items.*.images' => [
                    'nullable',
                    'array',
                    'max:5',
                ],

                'items.*.images.*' => [
                    'image',
                    'mimes:jpeg,png,jpg,gif,avif',
                    'max:5120',
                ],

                /*
                 * General return information
                 */
                'return_reason' => [
                    'nullable',
                    'string',
                    'max:1000',
                ],

                /*
                 * General return images
                 */
                'return_images' => [
                    'nullable',
                    'array',
                    'max:10',
                ],

                'return_images.*' => [
                    'image',
                    'mimes:jpeg,png,jpg,gif,avif',
                    'max:5120',
                ],
            ]);

            /*
             * Store uploaded images and convert them
             * into file paths.
             */
            $processedData = $this->processReturnImages($validated);

            /*
             * Send only processed data to service.
             */
            $result = $this->returnService->initiateReturn(
                auth()->id(),
                $processedData
            );

            return response()->json($result);
        } catch (ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid parameters provided.',
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
     * Process and store return images.
     */
    private function processReturnImages(array $data): array
    {
        /*
         * General images.
         */
        $generalImagePaths = [];

        if (
            isset($data['return_images']) &&
            is_array($data['return_images'])
        ) {
            foreach ($data['return_images'] as $image) {

                if (!$image) {
                    continue;
                }

                $path = $image->store(
                    'returns/general',
                    'public'
                );

                $generalImagePaths[] = $path;
            }
        }

        /*
         * Store item-specific images.
         */
        if (
            isset($data['items']) &&
            is_array($data['items'])
        ) {
            foreach ($data['items'] as $index => $item) {

                $itemImagePaths = [];

                if (
                    isset($item['images']) &&
                    is_array($item['images'])
                ) {
                    foreach ($item['images'] as $image) {

                        if (!$image) {
                            continue;
                        }

                        $path = $image->store(
                            'returns/items',
                            'public'
                        );

                        $itemImagePaths[] = $path;
                    }
                }

                /*
                 * Replace uploaded files with paths.
                 */
                $data['items'][$index]['image_paths'] = $itemImagePaths;

                /*
                 * Never send UploadedFile objects to service.
                 */
                unset($data['items'][$index]['images']);
            }
        }

        /*
         * Remove original return_images UploadedFile array.
         */
        unset($data['return_images']);

        /*
         * Always send an array.
         */
        $data['general_image_paths'] = $generalImagePaths;

        return $data;
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
    // public function adminMarkReceived(int $returnId): JsonResponse
    // {
    //     try {
    //         $result = $this->returnService->markReturnReceived($returnId);

    //         return response()->json($result);
    //     } catch (Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 400);
    //     }
    // }

    public function adminMarkReceived(int $returnId): JsonResponse
    {
        try {
            $result = $this->returnService->markReturnReceived($returnId);

            return response()->json($result, 200);
        } catch (\Throwable $e) {

            Log::error('Admin mark return received failed', [
                'return_id' => $returnId,
                'error' => $e->getMessage(),
            ]);

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

    /**
     * Admin: Approve Cooling-Off Withdrawal
     * POST /api/admin/cooling-off/{returnId}/approve
    */
    public function approveCoolingOff(Request $request, int $returnId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'admin_notes' => 'nullable|string|max:500',
            ]);

            $result = $this->returnService->approveCoolingOff(
                $returnId,
                auth()->id(),
                $validated['admin_notes'] ?? null
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Cooling-off approval failed', [
                'return_id' => $returnId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Admin: Reject Cooling-Off Withdrawal
     * POST /api/admin/cooling-off/{returnId}/reject
    */
    public function rejectCoolingOff(Request $request, int $returnId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $result = $this->returnService->rejectCoolingOff(
                $returnId,
                auth()->id(),
                $validated['rejection_reason']
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Cooling-off rejection failed', [
                'return_id' => $returnId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}