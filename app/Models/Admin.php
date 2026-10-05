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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(AdminRole::class, 'admin_admin_role', 'admin_id', 'admin_role_id');
    }

    public function hasPermission($permissionSlug)
    {
        foreach ($this->roles as $role) {
            if ($role->permissions->contains('slug', $permissionSlug)) {
                return true;
            }
        }
        return false;
    }

    public function hasRole($roleSlug)
    {
        return $this->roles->contains('slug', $roleSlug);
    }

    public function getAllPermissions()
    {
        $permissions = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->slug;
            }
        }
        return array_unique($permissions);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'admin_warehouse_assignments')
            ->withPivot(['is_primary', 'is_active', 'assigned_from', 'assigned_until'])
            ->withTimestamps();
    }

    public function warehouseAssignments()
    {
        return $this->hasMany(AdminWarehouseAssignment::class);
    }

    public function primaryWarehouse()
    {
        return $this->hasOne(AdminWarehouseAssignment::class)
            ->where('is_primary', true)
            ->where('is_active', true);
    }
}
