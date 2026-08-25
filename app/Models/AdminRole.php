<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRole extends Model
{
    protected $table = 'admin_roles';
    protected $fillable = ['name', 'slug', 'description'];

    public function permissions()
    {
        return $this->belongsToMany(AdminPermission::class, 'admin_role_permissions', 'admin_role_id', 'admin_permission_id');
    }

    public function admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_admin_role', 'admin_role_id', 'admin_id');
    }
}