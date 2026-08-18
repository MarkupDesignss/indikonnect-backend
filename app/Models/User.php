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

    /**
     * The attributes that are mass assignable.
     * If you prefer guarded, you can use $guarded, but $fillable is safer.
     * Here we keep $guarded = [] for flexibility (as per your original).
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'terms_condition' => 'boolean',
        'is_registered' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ============================================================
    // JWT Methods (if using JWT)
    // ============================================================
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // ============================================================
    // Relationships
    // ============================================================
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_users');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @deprecated Use distributorProfile() instead
     */
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

    // ============================================================
    // FRD Required Relationships
    // ============================================================

    /**
     * Get the distributor's KYC profile (FR-ON-005/006/007)
     */
    public function distributorProfile()
    {
        return $this->hasOne(DistributorProfile::class);
    }

    /**
     * Get the user's saved addresses (FR-AU-007)
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get the user's shopping cart (FR-ST-010)
     */
    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * Get user's orders (FR-CO-008)
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get coin redemption records (FR-CO-004, FR-DP-006)
     */
    public function coinRedemptions()
    {
        return $this->hasMany(CoinRedemption::class);
    }

    /**
     * Get distributor's sponsor (FR-ON-002)
     */
    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    /**
     * Get distributor's downline (FR-DP-009)
     */
    public function downline()
    {
        return $this->hasMany(User::class, 'sponsor_id');
    }

    // ============================================================
    // Scopes
    // ============================================================
    public function scopeUser($query)
    {
        return $query->where('account_type', 'user');
    }

    public function scopeDistributor($query)
    {
        return $query->where('account_type', 'distributor');
    }

    // Keep old spelling for backward compatibility if needed
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

    // ============================================================
    // Accessors
    // ============================================================
    public function getProfilePictureUrlAttribute()
    {
        if ($this->profile_picture) {
            return asset('storage/' . $this->profile_picture);
        }
        return null;
    }

    // ============================================================
    // Helper Methods (FRD-based)
    // ============================================================

    /**
     * Check if user is a distributor (correct spelling)
     */
    public function isDistributor(): bool
    {
        return $this->account_type === 'distributor';
    }

    /**
     * Check if user is a customer (FR-AU-001)
     */
    public function isCustomer(): bool
    {
        return $this->account_type === 'customer';
    }

    /**
     * Check if user is an admin (FR-AU-001)
     */
    public function isAdmin(): bool
    {
        return $this->account_type === 'admin';
    }

    /**
     * Check if user is an active distributor (FR-ON-011)
     */
    public function isActiveDistributor(): bool
    {
        return $this->isDistributor()
            && $this->distributor_status === 'active'
            && $this->is_active;
    }

    /**
     * Check if distributor application is pending (FR-ON-010)
     */
    public function isPendingDistributor(): bool
    {
        return $this->isDistributor()
            && $this->distributor_status === 'pending';
    }

    /**
     * Check if user is a legacy "user" type
     */
    public function isUser()
    {
        return $this->account_type === 'user';
    }

    /**
     * Check if distributor status is approved (old method name)
     * @deprecated Use isActiveDistributor() instead
     */
    public function isBusinessApproved()
    {
        return $this->distributor_status === 'approved';
    }

    /**
     * Check if distributor status is pending (old method name)
     * @deprecated Use isPendingDistributor() instead
     */
    public function isBusinessPending()
    {
        return $this->distributor_status === 'pending';
    }

    /**
     * Check if user has a specific role (FR-AD-001)
     */
    public function hasRole($roleSlug)
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Get user's display name
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->full_name ?? $this->email ?? 'User';
    }

    public function scopeDistributors($query)
    {
        return $query->where('account_type', 'distributer')->where('is_active', 1);
    }
}
