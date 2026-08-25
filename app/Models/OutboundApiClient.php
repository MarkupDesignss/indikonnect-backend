<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OutboundApiClient extends Model
{
    protected $fillable = [
        'client_name', 'api_key', 'secret', 'scopes', 'last_used_at', 'expires_at', 'is_active'
    ];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public static function generateApiKey(): string
    {
        return 'ik_' . Str::random(32);
    }

    public static function generateSecret(): string
    {
        return Str::random(64);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? []);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}