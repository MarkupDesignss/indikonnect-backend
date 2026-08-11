<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorProfile extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'distributor_profiles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'encrypted_aadhaar',
        'aadhaar_verified',
        'aadhaar_verified_at',
        'aadhaar_consent',
        'encrypted_pan',
        'pan_verified',
        'pan_verified_at',
        'encrypted_bank_account',
        'bank_ifsc',
        'bank_name',
        'branch_name',
        'account_type',
        'bank_verified',
        'bank_holder_name',
        'kyc_status',
        'location_consent',
        'location_consent_at',
        'latitude',
        'longitude',
        'registration_completed',
        'submitted_at',
        'terms_accepted_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'aadhaar_verified' => 'boolean',
        'aadhaar_verified_at' => 'datetime',
        'aadhaar_consent' => 'boolean',
        'pan_verified' => 'boolean',
        'pan_verified_at' => 'datetime',
        'bank_verified' => 'boolean',
        'location_consent' => 'boolean',
        'location_consent_at' => 'datetime',
        'registration_completed' => 'boolean',
        'submitted_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if KYC is verified.
     */
    public function isKycVerified(): bool
    {
        return $this->kyc_status === 'verified';
    }

    /**
     * Check if KYC is pending.
     */
    public function isKycPending(): bool
    {
        return $this->kyc_status === 'pending';
    }

    /**
     * Check if KYC is rejected.
     */
    public function isKycRejected(): bool
    {
        return $this->kyc_status === 'rejected';
    }

    /**
     * Check if the distributor has completed registration.
     */
    public function isRegistrationComplete(): bool
    {
        return (bool) $this->registration_completed;
    }
}