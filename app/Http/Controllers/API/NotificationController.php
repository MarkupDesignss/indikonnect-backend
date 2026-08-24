<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    protected AdminNotificationService $notificationService;

    public function __construct(AdminNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all notifications for the authenticated admin
     */
    public function index(Request $request): JsonResponse
    {
        $adminId = auth()->id();
        $notifications = $this->notificationService->getAll($adminId);

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Get a single notification
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $adminId = auth()->id();
        $notification = $this->notificationService->getOne($adminId, $id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $notification
        ]);
    }

    /**
     * Mark a single notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $adminId = auth()->id();
        $result = $this->notificationService->markAsRead($adminId, $id);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found or already read'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read'
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $adminId = auth()->id();
        $this->notificationService->markAllAsRead($adminId);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read'
        ]);
    }

    /**
     * Delete a single notification
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $adminId = auth()->id();
        $result = $this->notificationService->deleteOne($adminId, $id);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully'
        ]);
    }

    /**
     * Delete all notifications
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $adminId = auth()->id();
        $this->notificationService->deleteAll($adminId);

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted successfully'
        ]);
    }
}
