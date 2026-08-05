<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $fillable = [
        'type',
        'title',
        'logo',
        'slug',
        'favicon',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
        'favicon_url',
    ];

    public function getLogoUrlAttribute()
    {
        return $this->logo
            ? asset('storage/' . $this->logo)
            : null;
    }

    public function getFaviconUrlAttribute()
    {
        return $this->favicon
            ? asset('storage/' . $this->favicon)
            : null;
    }
}
