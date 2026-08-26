<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    // Relationships
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // Accessors
    public function getSubtotalAttribute()
    {
        return $this->unit_price * $this->quantity;
    }

    // Get current price based on variant or product
    public function getCurrentPrice($user = null)
    {
        if ($this->variant) {
            $isDistributor = $user && $user->account_type === 'distributor';
            return $isDistributor
                ? ($this->variant->distributor_price ?? $this->variant->retail_price)
                : $this->variant->retail_price;
        }

        if ($this->product) {
            $isDistributor = $user && $user->account_type === 'distributor';
            return $isDistributor
                ? ($this->product->distributor_price ?? $this->product->retail_price)
                : $this->product->retail_price;
        }

        return 0;
    }
}
