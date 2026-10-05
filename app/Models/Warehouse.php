<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'pincode',
        'country',
        'contact_person',
        'contact_number',
        'contact_email',
        'is_active',
        'is_default',
        'total_capacity',
        'opening_time',
        'closing_time',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'is_default'     => 'boolean',
        'total_capacity' => 'integer',
    ];

    public function assignments()
    {
        return $this->hasMany(AdminWarehouseAssignment::class, 'warehouse_id');
    }

    public function admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_warehouse_assignments')
            ->withPivot([
                'is_primary',
                'is_active',
            ])
            ->withTimestamps();
    }
    public function assignmentsMade()
    {
        return $this->hasMany(AdminWarehouseAssignment::class, 'assigned_by');
    }

    // Only currently active admins
    public function activeAdmins()
    {
        return $this->belongsToMany(Admin::class, 'admin_warehouse_assignments')
            ->wherePivot('is_active', true)
            ->withPivot(['is_primary']);
    }
}
