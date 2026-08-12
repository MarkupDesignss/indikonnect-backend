<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_billing' => 'boolean',
        'is_delivery' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get full address as string
     */
    public function getFullAddressAttribute()
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->postcode,
            $this->country,
        ]);
        return implode(', ', $parts);
    }

    /**
     * Get full billing address as string
     */
    public function getFullBillingAddressAttribute()
    {
        $parts = array_filter([
            $this->billing_address_line_1,
            $this->billing_address_line_2,
            $this->billing_city,
            $this->billing_state,
            $this->billing_postcode,
            $this->billing_country,
        ]);
        return implode(', ', $parts);
    }

    /**
     * Check if billing address is same as shipping address
     */
    public function isBillingSameAsShipping(): bool
    {
        return $this->billing_address_line_1 === $this->address_line_1 &&
            $this->billing_city === $this->city &&
            $this->billing_state === $this->state &&
            $this->billing_postcode === $this->postcode;
    }
}
