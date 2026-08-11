<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'coupons';

    protected $fillable = [
        'code',
        'title',
        'type',
        'value',
        'min_order',
        'max_order',
        'max_uses',
        'used_count',
        'expires_at',
        'is_active'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order' => 'decimal:2',
        'max_order' => 'decimal:2',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    // Check if coupon is valid
    public function isValid()
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    // Check if coupon can be used for order amount
    public function canApplyToOrder($orderAmount)
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->min_order && $orderAmount < $this->min_order) {
            return false;
        }

        if ($this->max_order && $orderAmount > $this->max_order) {
            return false;
        }

        return true;
    }

    // Calculate discount
    public function calculateDiscount($orderAmount)
    {
        if (!$this->canApplyToOrder($orderAmount)) {
            return 0;
        }

        if ($this->type === 'percentage') {
            return ($orderAmount * $this->value) / 100;
        } else { // fixed
            return min($this->value, $orderAmount);
        }
    }
}