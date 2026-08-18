<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class GrowthStep extends Model
{
    use SoftDeletes;

    protected $table = 'growth_steps';

    protected $fillable = [
        'title',
        'number',
        'subtitle',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'order' => 0,
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function scopeMainTitle($query)
    {
        return $query->whereNotNull('title');
    }

    public function scopeSteps($query)
    {
        return $query->whereNull('title');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }

    protected function formattedNumber(): Attribute
    {
        return Attribute::make(
            get: fn() => str_pad($this->number, 2, '0', STR_PAD_LEFT)
        );
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->order)) {
                $maxOrder = static::max('order') ?? 0;
                $model->order = $maxOrder + 1;
            }
        });
    }
}
