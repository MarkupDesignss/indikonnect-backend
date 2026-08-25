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
        'application_status',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
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
        // ========== NEW FOR TASK 2 ==========
        'reviewed_at' => 'datetime',
        'application_status' => 'string',
    ];

    // ================== RELATIONSHIPS ==================

    /**
     * Get the user that owns the profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who reviewed this application.
     * FR-ON-011: Records who approved/rejected/returned the application.
     */
    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    // ================== EXISTING KYC HELPERS ==================

    public function isKycVerified(): bool
    {
        return $this->kyc_status === 'verified';
    }

    public function isKycPending(): bool
    {
        return $this->kyc_status === 'pending';
    }

    public function isKycRejected(): bool
    {
        return $this->kyc_status === 'rejected';
    }

    public function isRegistrationComplete(): bool
    {
        return (bool) $this->registration_completed;
    }

    // ================== NEW APPLICATION STATUS HELPERS ==================

    /**
     * Check if application is in draft state.
     */
    public function isDraft(): bool
    {
        return $this->application_status === 'draft';
    }

    /**
     * Check if application has been submitted and is pending review.
     */
    public function isSubmitted(): bool
    {
        return $this->application_status === 'submitted';
    }

    /**
     * Check if application is currently under review by admin.
     */
    public function isUnderReview(): bool
    {
        return $this->application_status === 'under_review';
    }

    /**
     * Check if application was returned for correction.
     */
    public function isReturned(): bool
    {
        return $this->application_status === 'returned';
    }

    /**
     * Check if application is approved.
     */
    public function isApproved(): bool
    {
        return $this->application_status === 'approved';
    }

    /**
     * Check if application is rejected.
     */
    public function isRejected(): bool
    {
        return $this->application_status === 'rejected';
    }

    /**
     * Check if application can be edited by user.
     */
    public function isEditable(): bool
    {
        return in_array($this->application_status, ['draft', 'returned']);
    }

    /**
     * Check if application can be reviewed by admin.
     */
    public function isReviewable(): bool
    {
        return in_array($this->application_status, ['submitted', 'under_review']);
    }

    /**
     * Check if application reached a terminal state (approved or rejected).
     */
    public function isTerminal(): bool
    {
        return in_array($this->application_status, ['approved', 'rejected']);
    }

    /**
     * Get human-readable status label.
     */
    public function getApplicationStatusLabel(): string
    {
        return match ($this->application_status) {
            'draft' => 'Draft',
            'submitted' => 'Submitted - Pending Review',
            'under_review' => 'Under Review',
            'returned' => 'Returned for Correction',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Unknown',
        };
    }

    /**
     * Get status message for user display (FR-ON-010).
     */
    public function getStatusMessage(): string
    {
        return match ($this->application_status) {
            'draft' => 'Your application is incomplete. Please complete all steps.',
            'submitted' => 'Your application has been submitted and is awaiting review.',
            'under_review' => 'Your application is currently being reviewed by our team.',
            'returned' => 'Your application needs corrections. Please check the reason provided.',
            'approved' => 'Congratulations! Your application is approved. You can now access your distributor dashboard.',
            'rejected' => 'Your application was rejected. Please contact support for more information.',
            default => 'No application found.',
        };
    }

    /**
     * Check if user can resubmit (returned status).
     */
    public function canResubmit(): bool
    {
        return $this->application_status === 'returned';
    }

    /**
     * Check if all required verifications are complete for approval.
     */
    public function isReadyForApproval(): bool
    {
        return $this->aadhaar_verified
            && $this->pan_verified
            && $this->bank_verified
            && $this->application_status === 'submitted';
    }

    /**
     * Get the rejection reason (for returned or rejected).
     */
    public function getRejectionReason(): ?string
    {
        return $this->rejection_reason;
    }

    /**
     * Get reviewer name if reviewed.
     */
    public function getReviewerName(): ?string
    {
        return $this->reviewedByAdmin?->name;
    }
}