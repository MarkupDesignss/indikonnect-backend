<?php

namespace App\Services;

use App\Models\AdminNotification;
use Illuminate\Support\Collection;

class AdminNotificationService
{
    /**
     * Get all notifications for an admin
     */
    public function getAll(int $adminId): Collection
    {
        return AdminNotification::forAdmin($adminId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get unread notifications for an admin
     */
    public function getUnread(int $adminId): Collection
    {
        return AdminNotification::forAdmin($adminId)
            ->unread()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get a single notification (with admin verification)
     */
    public function getOne(int $adminId, int $notificationId): ?AdminNotification
    {
        return AdminNotification::forAdmin($adminId)
            ->where('id', $notificationId)
            ->first();
    }

    /**
     * Mark a single notification as read
     */
    public function markAsRead(int $adminId, int $notificationId): bool
    {
        $notification = $this->getOne($adminId, $notificationId);

        if (!$notification) {
            return false;
        }

        return $notification->update(['read' => true]);
    }

    /**
     * Mark all notifications as read for an admin
     */
    public function markAllAsRead(int $adminId): bool
    {
        return AdminNotification::forAdmin($adminId)
            ->unread()
            ->update(['read' => true]) > 0;
    }

    /**
     * Delete a single notification
     */
    public function deleteOne(int $adminId, int $notificationId): bool
    {
        $notification = $this->getOne($adminId, $notificationId);

        if (!$notification) {
            return false;
        }

        return $notification->delete();
    }

    /**
     * Delete all notifications for an admin
     */
    public function deleteAll(int $adminId): bool
    {
        return AdminNotification::forAdmin($adminId)->delete() > 0;
    }
}
