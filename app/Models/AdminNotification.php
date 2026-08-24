<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AdminNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'extra_data' => 'array',
        'read_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function scopeForAdmin(
        \Illuminate\Database\Eloquent\Builder $query,
        int $adminId
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('admin_id', $adminId);
    }

    public function markAsRead()
    {
        $this->update([
            'read_at' => now(),
        ]);
    }

    public function scopeUnread(
        \Illuminate\Database\Eloquent\Builder $query
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->whereNull('read_at');
    }
}
