<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    protected $guarded = [];
    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];
}
