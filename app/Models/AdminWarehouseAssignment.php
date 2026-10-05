<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AdminWarehouseAssignment extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'admin_warehouse_assignments';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'admin_id',
        'warehouse_id',
        'role_id',
        'is_primary',
        'assigned_from',
        'assigned_until',
        'is_active',
        'assigned_by',
        'notes',
    ];

    // NEW relationship
    public function role()
    {
        return $this->belongsTo(AdminRole::class, 'role_id');
    }

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_primary'     => 'boolean',
        'is_active'      => 'boolean',
        'assigned_from'  => 'date',
        'assigned_until' => 'date',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * (None hidden — pivot data is safe to expose)
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'is_current',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * The admin/sub-admin this assignment belongs to.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * The warehouse this assignment belongs to.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * The super admin who created this assignment.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_by');
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope: only active assignments.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: only inactive assignments.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope: only primary warehouse assignments.
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope: filter by warehouse.
     */
    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * Scope: filter by admin.
     */
    public function scopeForAdmin(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_id', $adminId);
    }

    /**
     * Scope: only currently valid assignments (respecting date range).
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('assigned_from')
                    ->orWhere('assigned_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('assigned_until')
                    ->orWhere('assigned_until', '>=', $today);
            });
    }

    /**
     * Scope: expired assignments (assigned_until passed).
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('assigned_until')
            ->where('assigned_until', '<', now()->toDateString());
    }

    // ============================================
    // ACCESSORS
    // ============================================

    /**
     * Check if the assignment is currently valid (active + within date range).
     */
    public function getIsCurrentAttribute(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->assigned_from && $this->assigned_from->gt($today)) {
            return false;
        }

        if ($this->assigned_until && $this->assigned_until->lt($today)) {
            return false;
        }

        return true;
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Check if this assignment is currently valid.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->is_current;
    }

    /**
     * Check if assignment is expired.
     */
    public function isExpired(): bool
    {
        return $this->assigned_until
            && $this->assigned_until->lt(now()->startOfDay());
    }

    /**
     * Check if assignment hasn't started yet.
     */
    public function isFuture(): bool
    {
        return $this->assigned_from
            && $this->assigned_from->gt(now()->startOfDay());
    }

    /**
     * Deactivate this assignment.
     */
    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Reactivate this assignment.
     */
    public function reactivate(): bool
    {
        return $this->update(['is_active' => true]);
    }

    /**
     * Make this the primary warehouse for the admin.
     * (Unsets primary on all other assignments of the same admin.)
     */
    public function makePrimary(): bool
    {
        return DB::transaction(function () {
            static::where('admin_id', $this->admin_id)
                ->where('id', '!=', $this->id)
                ->update(['is_primary' => false]);

            return $this->update(['is_primary' => true]);
        });
    }
}
