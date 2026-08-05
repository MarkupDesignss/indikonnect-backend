<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'role_users');
    }

    public function scopeUser($query)
    {
        return $query->where('slug', 'user');
    }

    public function scopeDistributer($query)
    {
        return $query->where('slug', 'distributer');
    }
}
