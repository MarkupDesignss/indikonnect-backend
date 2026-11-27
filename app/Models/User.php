<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $guarded = [];
    protected $hidden = [
        'password','remember_token','otp','otp_expires_at','email_otp','email_otp_expires_at',
        'temp_verification_token','temp_token','complete_pan_card','complete_aadhar_card',
    ];
    protected $casts = [
        'email_verified_at'=>'datetime','phone_verified_at'=>'datetime','otp_expires_at'=>'datetime',
        'terms_condition'=>'boolean','is_registered'=>'boolean','is_active'=>'boolean',
        'registration_completed_at'=>'datetime','activation_date'=>'datetime',
    ];
    public function getJWTIdentifier() { return $this->getKey(); }
    public function getJWTCustomClaims() { return []; }
    public function roles() { return $this->belongsToMany(Role::class,'role_users'); }
    public function role() { return $this->belongsTo(Role::class); }
    public function notificationSettings(): HasOne { return $this->hasOne(UserNotificationSetting::class); }
    public function refreshTokens() { return $this->hasMany(RefreshToken::class); }
    public function rejectedUser() { return $this->hasOne(RejectedUser::class); }
    public function distributorProfile() { return $this->hasOne(DistributorProfile::class); }
    public function addresses() { return $this->hasMany(Address::class); }
    public function cart() { return $this->hasOne(Cart::class); }
    public function orders() { return $this->hasMany(Order::class); }
    public function coinRedemptions() { return $this->hasMany(CoinRedemption::class); }
    // Existing sponsor_id stores external BA identifiers, not numeric users.id.
    public function sponsor() { return $this->belongsTo(User::class,'sponsor_id','distributor_id'); }
    public function latestCorrectionRequest() { return $this->hasOne(CorrectionRequest::class)->latestOfMany(); }
    public function downline() { return $this->hasMany(User::class,'sponsor_id','distributor_id'); }
    public function correctionRequests() { return $this->hasMany(CorrectionRequest::class,'user_id'); }
    public function scopeUser($query) { return $query->where('account_type','customer'); }
    public function scopeDistributor($query) { return $query->where('account_type','distributor'); }
    public function scopeDistributer($query) { return $this->scopeDistributor($query); }
    public function scopeRegistered($query) { return $query->where('is_registered',1); }
    public function scopeActive($query) { return $query->where('is_active',1); }
    public function getProfilePictureUrlAttribute() { return $this->profile_picture ? asset('storage/'.$this->profile_picture) : null; }
    public function isDistributor(): bool { return $this->account_type === 'distributor'; }
    public function isCustomer(): bool { return $this->account_type === 'customer'; }
    public function isAdmin(): bool { return $this->account_type === 'admin'; }
    public function isActiveDistributor(): bool { return $this->isDistributor() && $this->distributor_status === 'active' && $this->is_active; }
    public function isPendingDistributor(): bool { return $this->isDistributor() && $this->distributor_status === 'pending'; }
    public function isUser() { return $this->isCustomer(); }
    public function isBusinessApproved() { return $this->distributor_status === 'active'; }
    public function isBusinessPending() { return $this->isPendingDistributor(); }
    public function hasRole($roleSlug) { return $this->roles()->where('slug',$roleSlug)->exists(); }
    public function getDisplayNameAttribute(): string { return $this->full_name ?? $this->email ?? 'User'; }
    public function scopeDistributors($query) { return $query->where('account_type','distributor')->where('is_active',1); }
}
