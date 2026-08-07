<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = 'distributor_profiles';

    protected $fillable = [
        'id',
        'user_id',
        'encrypted_aadhaar',
        'encrypted_pan',
        'encrypted_bank_account',
        'bank_ifsc',
        'bank_holder_name',
        'kyc_status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'kyc_status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the business profile.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if KYC is completed
     */
    public function isKycCompleted(): bool
    {
        return $this->kyc_status === 'approved';
    }

    /**
     * Check if KYC is pending
     */
    public function isKycPending(): bool
    {
        return $this->kyc_status === 'pending';
    }

    /**
     * Check if KYC is rejected
     */
    public function isKycRejected(): bool
    {
        return $this->kyc_status === 'rejected';
    }
}
