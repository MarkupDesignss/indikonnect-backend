<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'order_id',
        'seller_name',
        'seller_gstin',
        'seller_address',
        'buyer_name',
        'buyer_gstin',
        'buyer_address',
        'delivery_state',
        'line_items',
        'subtotal_before_redemption',
        'coin_redeemed',
        'total_taxable',
        'total_cgst',
        'total_sgst',
        'total_igst',
        'total_tax',
        'total',
        'issued_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'subtotal_before_redemption' => 'decimal:2',
        'coin_redeemed' => 'decimal:2',
        'total_taxable' => 'decimal:2',
        'total_cgst' => 'decimal:2',
        'total_sgst' => 'decimal:2',
        'total_igst' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}