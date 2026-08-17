<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutRun extends Model
{
    protected $fillable = ['period', 'status', 'total_gross', 'total_tds', 'total_net', 'released_at', 'created_by'];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(PayoutEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}