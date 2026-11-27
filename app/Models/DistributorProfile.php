<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorProfile extends Model
{
    protected $table = 'distributor_profiles';
    protected $fillable = [
        'user_id','gst_in','company_name','encrypted_aadhaar','aadhaar_verified','aadhaar_verified_at',
        'aadhaar_consent','encrypted_pan','pan_verified','pan_verified_at','encrypted_bank_account',
        'bank_ifsc','bank_name','title','type_of_entity','branch_name','account_type','bank_verified',
        'bank_holder_name','kyc_status','kyc_status_updated_at','location_consent','location_consent_at',
        'latitude','longitude','city','state','pincode','registration_completed','submitted_at',
        'terms_accepted_at','application_status','rejection_reason','reviewed_by','reviewed_at','withdrawn_at',
    ];
    // Never expose credentials or full account numbers through default serialization.
    // Do not enable mass assignment of the plaintext complete_bank_account field.
    protected $hidden = ['encrypted_aadhaar','encrypted_pan','encrypted_bank_account','complete_bank_account'];
    protected $casts = [
        'aadhaar_verified'=>'boolean','aadhaar_verified_at'=>'datetime','aadhaar_consent'=>'boolean',
        'pan_verified'=>'boolean','pan_verified_at'=>'datetime','bank_verified'=>'boolean',
        'location_consent'=>'boolean','location_consent_at'=>'datetime','registration_completed'=>'boolean',
        'submitted_at'=>'datetime','terms_accepted_at'=>'datetime','reviewed_at'=>'datetime',
        'application_status'=>'string','kyc_status_updated_at'=>'datetime','withdrawn_at'=>'datetime',
    ];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewedByAdmin(): BelongsTo { return $this->belongsTo(Admin::class,'reviewed_by'); }
    public function isKycVerified(): bool { return $this->kyc_status === 'verified'; }
    public function isKycPending(): bool { return $this->kyc_status === 'pending'; }
    public function isKycRejected(): bool { return $this->kyc_status === 'rejected'; }
    public function isRegistrationComplete(): bool { return (bool) $this->registration_completed; }
    public function isDraft(): bool { return $this->application_status === 'draft'; }
    public function isSubmitted(): bool { return $this->application_status === 'submitted'; }
    public function isUnderReview(): bool { return $this->application_status === 'under_review'; }
    public function isReturned(): bool { return $this->application_status === 'returned'; }
    public function isApproved(): bool { return $this->application_status === 'approved'; }
    public function isRejected(): bool { return $this->application_status === 'rejected'; }
    public function isEditable(): bool { return in_array($this->application_status,['draft','returned'],true); }
    public function isReviewable(): bool { return in_array($this->application_status,['submitted','under_review'],true); }
    public function isTerminal(): bool { return in_array($this->application_status,['approved','rejected'],true); }
    public function getApplicationStatusLabel(): string { return match ($this->application_status) {
        'draft'=>'Draft','submitted'=>'Submitted - Pending Review','under_review'=>'Under Review',
        'returned'=>'Returned for Correction','approved'=>'Approved','rejected'=>'Rejected','withdrawn'=>'Withdrawn',default=>'Unknown',
    }; }
    public function getStatusMessage(): string { return match ($this->application_status) {
        'draft'=>'Your application is incomplete. Please complete all steps.',
        'submitted'=>'Your application has been submitted and is awaiting review.',
        'under_review'=>'Your application is currently being reviewed by our team.',
        'returned'=>'Your application needs corrections. Please check the reason provided.',
        'approved'=>'Congratulations! Your application is approved. You can now access your distributor dashboard.',
        'rejected'=>'Your application was rejected. Please contact support for more information.',
        'withdrawn'=>'Your application was withdrawn.', default=>'No application found.',
    }; }
    public function canResubmit(): bool { return $this->isReturned(); }
    public function isReadyForApproval(): bool { return $this->aadhaar_verified && $this->pan_verified && $this->bank_verified && $this->application_status === 'submitted'; }
    public function getRejectionReason(): ?string { return $this->rejection_reason; }
    public function getReviewerName(): ?string { return $this->reviewedByAdmin?->name; }
}
