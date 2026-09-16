<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BuybackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AdminBuybackController extends Controller
{
    protected BuybackService $buybackService;

    public function __construct(BuybackService $buybackService)
    {
        $this->buybackService = $buybackService;
    }

    /**
     * List all buyback requests
     * GET /admin/buyback/requests
     */
    public function index(Request $request)
    {
        try {
            $status = $request->input('status');
            $perPage = (int) $request->input('per_page', 20);

            $data = $this->buybackService->getBuybackRequestsForAdmin($status, $perPage);

            return response()->json([
                'success' => true,
                'data' => $data['data'],
                'summary' => [
                    'total' => $data['total'],
                    'pending' => $data['pending'],
                    'approved' => $data['approved'],
                    'rejected' => $data['rejected'],
                    'received' => $data['received'],
                    'completed' => $data['completed'],
                ],
                'pagination' => $data['pagination'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch buyback requests', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch buyback requests: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single buyback request details
     * GET /admin/buyback/requests/{id}
     */
    public function show(int $id)
    {
        try {
            $data = $this->buybackService->getBuybackForAdmin($id);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Buyback request not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch buyback details', [
                'return_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch buyback details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a buyback request
     * POST /admin/buyback/requests/{id}/approve
     *
     * Body:
     * - admin_notes: nullable|string|max:2000
     */
    public function approve(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = Auth::user();

        try {
            $result = $this->buybackService->approveBuyback(
                $id,
                $admin->id,
                $request->input('admin_notes')
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'return_id' => $result['return_id'],
                    'status' => $result['status'],
                    'order_status' => $result['order_status'],
                    'order_return_status' => $result['order_return_status'],
                    'refund_amount' => $result['refund_amount'],
                    'admin_notes' => $result['admin_notes'],
                    'stock_restored' => $result['stock_restored'],
                    'reversal_triggered' => $result['reversal_triggered'],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Buyback request not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to approve buyback', [
                'return_id' => $id,
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject a buyback request
     * POST /admin/buyback/requests/{id}/reject
     *
     * Body:
     * - rejection_reason: required|string|max:2000
     */
    public function reject(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = Auth::user();

        try {
            $result = $this->buybackService->rejectBuyback(
                $id,
                $admin->id,
                $request->input('rejection_reason')
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'return_id' => $result['return_id'],
                    'status' => $result['status'],
                    'order_status' => $result['order_status'],
                    'order_return_status' => $result['order_return_status'],
                    'rejection_reason' => $result['rejection_reason'],
                    'items' => $result['items'],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Buyback request not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to reject buyback', [
                'return_id' => $id,
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Mark buyback items as received + process refund (combined)
     * POST /admin/buyback/requests/{id}/mark-received
     *
     * Body:
     * - refund_amount: required|numeric|min:0.01  (Admin can override the refund amount)
     * - admin_notes: nullable|string|max:2000
     */
    public function markReceived(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'refund_amount' => 'required|numeric|min:0.01',
            'admin_notes'   => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = Auth::user();

        try {
            $result = $this->buybackService->markBuybackReceived(
                $id,
                (float) $request->input('refund_amount'),
                $request->input('admin_notes'),
                $admin->id
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'return_id'               => $result['return_id'],
                    'status'                  => $result['status'],
                    'refund_amount'           => $result['refund_amount'],
                    'refund_transaction_id'   => $result['refund_transaction_id'] ?? null,
                    'refund_status'           => $result['refund_status'] ?? null,
                    'admin_notes'             => $result['admin_notes'] ?? null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Buyback request not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to mark buyback as received', [
                'return_id' => $id,
                'admin_id'  => $admin->id,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get buyback summary statistics
     * GET /admin/buyback/summary
     */
    public function summary()
    {
        try {
            $data = $this->buybackService->getBuybackSummary();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch buyback summary', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch buyback summary: ' . $e->getMessage(),
            ], 500);
        }
    }
}
