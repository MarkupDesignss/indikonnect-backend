<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderReturn extends Model
{
    use SoftDeletes;

    protected $table = 'returns'; // Your existing table name

    protected $fillable = [
        'order_id',
        'user_id',
        'items',
        'status',
        'reason',
        'admin_notes',
        'approved_at',
        'received_at',
        'completed_at',
        'admin_id',
    ];

    protected $casts = [
        'items' => 'array',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function refund()
    {
        return $this->hasOne(Refund::class);
    }
}