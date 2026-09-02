<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait AuditLogTrait
{
    /**
     * Log admin action to audit_logs table
     */
    protected function logAudit(
        string $action,
        string $module,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?string $ipAddress = null
    ): void {
        AuditLog::create([
            'user_id' => $userId ?? (Auth::guard('admin')->id() ?? Auth::id()),
            'action' => $action,
            'module' => $module,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $ipAddress ?? request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get current admin user ID
     */
    protected function getAdminId(): ?int
    {
        return Auth::guard('admin')->id() ?? Auth::id();
    }

    /**
     * Get request IP address
     */
    protected function getClientIp(): string
    {
        return request()->ip() ?? '0.0.0.0';
    }
}
