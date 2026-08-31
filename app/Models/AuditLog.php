<?php
// app/Models/AuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    
    protected $fillable = [
        'user_id',
        'action',
        'module',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Simple log helper
    public static function log($data)
    {
        return self::create([
            'user_id' => $data['user_id'] ?? auth()->id(),
            'action' => $data['action'],
            'module' => $data['module'] ?? 'general',
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
        ]);
    }
}