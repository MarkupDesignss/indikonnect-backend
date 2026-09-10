<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProformaInvoice extends Model
{
    protected $table = 'proforma_invoices';

    protected $guarded = [];

    protected $casts = [
        'line_items' => 'array',
        'summary_snapshot' => 'array',

        'subtotal_before_redemption' => 'decimal:2',
        'coin_redeemed' => 'decimal:2',
        'total_taxable' => 'decimal:2',

        'total_cgst' => 'decimal:2',
        'total_sgst' => 'decimal:2',
        'total_igst' => 'decimal:2',
        'total_tax' => 'decimal:2',

        'coupon_discount' => 'decimal:2',
        'shipping_charge' => 'decimal:2',

        'subtotal_after_discount' => 'decimal:2',
        'total' => 'decimal:2',
        'total_payable' => 'decimal:2',

        'issued_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
