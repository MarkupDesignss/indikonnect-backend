<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeMaster extends Model
{
    protected $fillable = ['attribute_key', 'is_required', 'sort_order'];
    
    protected $casts = [
        'is_required' => 'boolean',
    ];
    
    public function values()
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order');
    }
    
    public function getDisplayNameAttribute()
    {
        return ucwords(str_replace('_', ' ', $this->attribute_key));
    }
}