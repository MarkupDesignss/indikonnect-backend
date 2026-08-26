<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderShippingDetail extends Model
{
    protected $fillable = [
        'order_id',
        'order_line_id',
        'courier_tracking_number',
        'courier_company',
        'delivery_notes',
        'courier_delivery_date',
        'status'
    ];

    protected $casts = [
        'courier_delivery_date' => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderLine()
    {
        return $this->belongsTo(OrderLine::class);
    }
}
