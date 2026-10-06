<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_image',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // ============================================
    // GLOBAL ROLES (Admin ↔ Role)
    // ============================================

    public function roles()
    {
        return $this->belongsToMany(
            AdminRole::class,
            'admin_admin_role',   // pivot table
            'admin_id',
            'admin_role_id'
        );
    }

    // ============================================
    // WAREHOUSES (Admin ↔ Warehouse)
    // ============================================

    /**
     * All warehouses assigned to this admin.
     * ✅ pivot me role_id bhi include kiya
     */
    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'admin_warehouse_assignments')
            ->withPivot([
                'id',
                'role_id',
                'is_primary',
                'is_active',
                'assigned_from',
                'assigned_until',
                'assigned_by',
                'notes',
            ])
            ->withTimestamps();
    }

    /**
     * All warehouse assignments (with full data).
     */
    public function warehouseAssignments()
    {
        return $this->hasMany(AdminWarehouseAssignment::class, 'admin_id');
    }

    /**
     * Only active warehouse assignments.
     */
    public function activeWarehouseAssignments()
    {
        return $this->hasMany(AdminWarehouseAssignment::class, 'admin_id')
            ->where('is_active', true);
    }

    /**
     * Primary warehouse assignment.
     */
    public function primaryWarehouse()
    {
        return $this->hasOne(AdminWarehouseAssignment::class, 'admin_id')
            ->where('is_primary', true)
            ->where('is_active', true)
            ->latestOfMany();   // safety: agar multiple ho to latest le
    }

    /**
     * Assignments this admin created (as super admin).
     */
    public function assignmentsMade()
    {
        return $this->hasMany(AdminWarehouseAssignment::class, 'assigned_by');
    }

    // ============================================
    // PERMISSION HELPERS (Global)
    // ============================================

    public function hasPermission($permissionSlug): bool
    {
        foreach ($this->roles as $role) {
            if ($role->permissions->contains('slug', $permissionSlug)) {
                return true;
            }
        }
        return false;
    }

    public function hasRole($roleSlug): bool
    {
        return $this->roles->contains('slug', $roleSlug);
    }

    public function getAllPermissions(): array
    {
        $permissions = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->slug;
            }
        }
        return array_values(array_unique($permissions));
    }

    // ============================================
    // WAREHOUSE HELPERS (NEW)
    // ============================================

    /**
     * Check if admin has access to a specific warehouse.
     */
    public function hasWarehouseAccess(int $warehouseId): bool
    {
        return $this->warehouseAssignments()
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if admin has a specific role in a specific warehouse.
     */
    public function hasRoleInWarehouse(int $warehouseId, string $roleSlug): bool
    {
        return $this->warehouseAssignments()
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->whereHas('role', function ($q) use ($roleSlug) {
                $q->where('slug', $roleSlug);
            })
            ->exists();
    }

    /**
     * Get admin's role in a specific warehouse (returns role or null).
     */
    public function getRoleInWarehouse(int $warehouseId)
    {
        $assignment = $this->warehouseAssignments()
            ->with('role')
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->first();

        return $assignment?->role;
    }

    /**
     * Get all active warehouse IDs for this admin.
     */
    public function getActiveWarehouseIds(): array
    {
        return $this->warehouseAssignments()
            ->where('is_active', true)
            ->pluck('warehouse_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get warehouses where admin has a specific role.
     */
    public function getWarehousesByRole(string $roleSlug)
    {
        return $this->warehouseAssignments()
            ->with('warehouse')
            ->where('is_active', true)
            ->whereHas('role', fn($q) => $q->where('slug', $roleSlug))
            ->get()
            ->pluck('warehouse');
    }
}