<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'terms_condition' => 'boolean',
        'is_registered' => 'boolean',
        'is_active' => 'boolean',
    ];

    // JWT Methods
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // Relationships
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_users');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function businessProfile()
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function rejectedUser()
    {
        return $this->hasOne(RejectedUser::class);
    }

    // Scopes
    public function scopeUser($query)
    {
        return $query->where('account_type', 'user');
    }

    public function scopeDistributer($query)
    {
        return $query->where('account_type', 'distributor');
    }

    public function scopeRegistered($query)
    {
        return $query->where('is_registered', 1);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    // Accessors
    public function getProfilePictureUrlAttribute()
    {
        if ($this->profile_picture) {
            return asset('storage/' . $this->profile_picture);
        }
        return null;
    }

    // Helper Methods
    public function hasRole($roleSlug)
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    public function isUser()
    {
        return $this->account_type === 'user';
    }

    public function isDistributer()
    {
        return $this->account_type === 'distributor';
    }

    public function isBusinessApproved()
    {
        return $this->distributor_status  === 'approved';
    }

    public function isBusinessPending()
    {
        return $this->distributor_status  === 'pending';
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}