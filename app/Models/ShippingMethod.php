<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'base_rate',
        'rate_type',
        'rate_value',
        'min_order_amount',
        'max_order_amount',
        'estimated_days',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'rate_value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_order_amount' => 'decimal:2',
        'estimated_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];
}
