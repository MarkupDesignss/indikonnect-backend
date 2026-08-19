<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'group', 'key', 'value', 'data_type',
        'description', 'is_editable'
    ];

    protected $casts = [
        'is_editable' => 'boolean',
    ];

    // Accessor to return typed value based on data_type
    public function getTypedValueAttribute(): mixed
    {
        return match ($this->data_type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            'email', 'string' => (string) $this->value,
            default => $this->value,
        };
    }

    // Clear cache on save/update/delete
    protected static function booted(): void
    {
        static::saved(fn() => Cache::forget('settings'));
        static::deleted(fn() => Cache::forget('settings'));
    }
}