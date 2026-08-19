<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationSettingsController extends Controller
{
    /**
     * Default notification settings
     */
    private function defaultSettings(): array
    {
        return [
            'email_notifications' => true,
            'order_updates' => true,
            'payment_alerts' => true,
            'promotional_emails' => false,
            'security_alerts' => true,
        ];
    }

    /**
     * Allowed notification types
     */
    private function allowedTypes(): array
    {
        return [
            'email_notifications',
            'order_updates',
            'payment_alerts',
            'promotional_emails',
            'security_alerts',
        ];
    }

    /**
     * Get user notification settings
     */
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id(); // Get authenticated user ID

        $settings = UserNotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            $this->defaultSettings()
        );

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Update all notification settings
     */
    public function update(Request $request): JsonResponse
    {
        $userId = Auth::id(); // Get authenticated user ID

        $validated = $request->validate([
            'email_notifications' => ['sometimes', 'boolean'],
            'order_updates' => ['sometimes', 'boolean'],
            'payment_alerts' => ['sometimes', 'boolean'],
            'promotional_emails' => ['sometimes', 'boolean'],
            'security_alerts' => ['sometimes', 'boolean'],
        ]);

        $settings = UserNotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            $this->defaultSettings()
        );

        $settings->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Notification settings updated successfully',
            'data' => $settings->fresh()
        ]);
    }

    /**
     * Toggle a specific notification setting
     */
    public function toggle(Request $request): JsonResponse
    {
        $userId = Auth::id(); // Get authenticated user ID

        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                Rule::in($this->allowedTypes()),
            ],
            'status' => [
                'required',
                'boolean',
            ],
        ]);

        $settings = UserNotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            $this->defaultSettings()
        );

        $type = $validated['type'];
        $status = $validated['status'];

        $settings->update([
            $type => $status,
        ]);

        return response()->json([
            'success' => true,
            'message' => ucfirst(
                str_replace('_', ' ', $type)
            ) . ' toggled successfully',
            'data' => [
                'type' => $type,
                'status' => (bool) $status,
            ]
        ]);
    }

    /**
     * Activate all notifications
     */
    public function activateAll(Request $request): JsonResponse
    {
        $userId = Auth::id(); // Get authenticated user ID

        $settings = UserNotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            $this->defaultSettings()
        );

        $settings->update([
            'email_notifications' => true,
            'order_updates' => true,
            'payment_alerts' => true,
            'promotional_emails' => true,
            'security_alerts' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications activated successfully',
            'data' => $settings->fresh()
        ]);
    }

    /**
     * Deactivate all notifications
     */
    public function deactivateAll(Request $request): JsonResponse
    {
        $userId = Auth::id(); // Get authenticated user ID

        $settings = UserNotificationSetting::firstOrCreate(
            ['user_id' => $userId],
            $this->defaultSettings()
        );

        $settings->update([
            'email_notifications' => false,
            'order_updates' => false,
            'payment_alerts' => false,
            'promotional_emails' => false,
            'security_alerts' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications deactivated successfully',
            'data' => $settings->fresh()
        ]);
    }
}
