<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderLine;
use App\Models\Notification;
use App\Models\AdminNotification;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancellationApprovalController extends Controller
{
    protected $checkoutService;
    protected $returnService;

    public function __construct(CheckoutService $checkoutService, ReturnService $returnService)
    {
        $this->checkoutService = $checkoutService;
        $this->returnService = $returnService;
    }

    /**
     * Get all pending cancellation requests
     */
    public function getPendingRequests(Request $request): JsonResponse
    {
        $pendingRequests = OrderLine::whereIn('delivery_status', [
                'cancel_pending',
                'cancelled',
                'cancelled_rejected'
            ])
            ->with(['order', 'order.user', 'product', 'variant'])
            ->orderBy('cancellation_requested_at', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $pendingRequests,
        ]);
    }

    /**
     * Get specific cancellation request details
     */
    public function getRequestDetails(int $orderLineId): JsonResponse
    {
        $orderLine = OrderLine::where('id', $orderLineId)
            ->where('delivery_status', 'cancel_pending')
            ->with(['order', 'order.user', 'product', 'variant'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $orderLine,
        ]);
    }

    /**
     * Approve cancellation request - Uses your existing cancelOrder function
     */
    public function approve(Request $request, int $orderLineId): JsonResponse
    {
        try {
            $request->validate([
                'admin_notes' => 'nullable|string|max:500',
            ]);

            $orderLine = OrderLine::where('id', $orderLineId)
                ->where('delivery_status', 'cancel_pending')
                ->with(['order', 'product', 'variant'])
                ->firstOrFail();

            $order = $orderLine->order;
            $reason = $orderLine->cancellation_reason;

            // CALL YOUR EXISTING cancelOrder FUNCTION HERE
            $result = $this->checkoutService->cancelOrder(
                $order->user_id,
                $order->order_reference,
                $orderLineId,
                $reason
            );

            // Mark admin notification as read
            AdminNotification::where('reference_type', 'order_line')
                ->where('reference_id', $orderLineId)
                ->where('type', 'order_cancellation_request')
                ->update(['read' => true]);

            // Send notification to user (approved)
            $this->sendUserNotification(
                $order->user_id,
                'Cancellation Approved',
                "Your cancellation request for order #{$order->order_reference} has been approved and processed.",
                'order_cancellation_approved',
                [
                    'order_reference' => $order->order_reference,
                    'order_line_id' => $orderLineId,
                    'admin_notes' => $request->admin_notes,
                    'refund_processed' => $order->amount_paid > 0
                ]
            );

            // Log approval
            Log::info('Cancellation request approved', [
                'order_line_id' => $orderLineId,
                'order_reference' => $order->order_reference,
                'admin_id' => Auth::guard('admin')->id(),
                'result' => $result
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Cancellation request approved and processed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Cancellation approval failed: ' . $e->getMessage(), [
                'order_line_id' => $orderLineId,
                'admin_id' => Auth::guard('admin')->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * NEW: Reject cancellation request
     */
    public function reject(Request $request, int $orderLineId): JsonResponse
    {
        try {
            $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            DB::transaction(function () use ($request, $orderLineId) {
                $orderLine = OrderLine::where('id', $orderLineId)
                    ->where('delivery_status', 'cancel_pending')
                    ->with(['order', 'order.user'])
                    ->firstOrFail();

                $order = $orderLine->order;

                // Update order line status to rejected
                $orderLine->update([
                    'delivery_status' => 'cancel_rejected',
                    'cancellation_rejection_reason' => $request->rejection_reason,
                    'updated_at' => now(),
                ]);

                // Mark admin notification as read
                AdminNotification::where('reference_type', 'order_line')
                    ->where('reference_id', $orderLineId)
                    ->where('type', 'order_cancellation_request')
                    ->update(['read' => true]);

                // Send notification to user (rejected)
                $this->sendUserNotification(
                    $order->user_id,
                    'Cancellation Rejected',
                    "Your cancellation request for order #{$order->order_reference} has been rejected.",
                    'order_cancellation_rejected',
                    [
                        'order_reference' => $order->order_reference,
                        'order_line_id' => $orderLineId,
                        'rejection_reason' => $request->rejection_reason,
                    ]
                );

                // Log rejection
                Log::info('Cancellation request rejected', [
                    'order_line_id' => $orderLineId,
                    'order_reference' => $order->order_reference,
                    'admin_id' => Auth::guard('admin')->id(),
                    'rejection_reason' => $request->rejection_reason,
                    'original_reason' => $orderLine->cancellation_reason
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Cancellation request rejected successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Cancellation rejection failed: ' . $e->getMessage(), [
                'order_line_id' => $orderLineId,
                'admin_id' => Auth::guard('admin')->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    protected function sendUserNotification(
        $userId,
        $title,
        $message,
        $type,
        $data = []
    ) {
        try {
            $user = User::find($userId);

            if (!$user) {
                Log::warning('User not found for notification', [
                    'user_id' => $userId,
                    'event_type' => $type,
                ]);

                return false;
            }

            // Use dynamic notification service
            $notificationService = app(\App\Services\NotificationService::class);

            // Add title/message as template data
            $notificationData = array_merge($data, [
                'title' => $title,
                'message' => $message,
            ]);

            return $notificationService->sendUserNotification(
                $user,
                $type,
                $notificationData,
                ['database']
            );
        } catch (\Exception $e) {
            Log::error('Failed to send user notification', [
                'user_id' => $userId,
                'event_type' => $type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
