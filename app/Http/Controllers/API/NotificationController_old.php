<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated admin
     */
    public function index(Request $request): JsonResponse
    {
        $adminId = auth()->id();

        $notifications = AdminNotification::where('admin_id', $adminId)
            ->orderBy('created_at', 'desc')
            ->get();

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

        $notification = AdminNotification::where('admin_id', $adminId)
            ->where('id', $id)
            ->first();

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

        $notification = AdminNotification::where('admin_id', $adminId)
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        if ($notification->read) {
            return response()->json([
                'success' => false,
                'message' => 'Notification already read'
            ], 400);
        }

        $notification->update(['read' => true]);

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

        $updated = AdminNotification::where('admin_id', $adminId)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'updated_count' => $updated
        ]);
    }

    /**
     * Delete a single notification
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $adminId = auth()->id();

        $notification = AdminNotification::where('admin_id', $adminId)
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->delete();

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

        $deleted = AdminNotification::where('admin_id', $adminId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted successfully',
            'deleted_count' => $deleted
        ]);
    }

    /**
     * Get only unread notifications for the authenticated admin
     */
    public function unread(Request $request): JsonResponse
    {
        $adminId = auth()->id();

        $notifications = AdminNotification::where('admin_id', $adminId)
            ->where('read', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $notifications->count()
        ]);
    }
}
