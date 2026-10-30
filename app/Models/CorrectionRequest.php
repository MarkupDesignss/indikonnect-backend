<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectionRequest extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'reason',
        'full_name',
        'date_of_birth',
        'status',
        'token',
        'phone',
        'type',
        'token_expires_at',
        'rejected_reason',
        'rejected_at',
        'token_used_at',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'rejected_at'      => 'datetime',
        'token_expires_at' => 'datetime',
        'token_used_at'    => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getReasonLabelAttribute(): string
    {
        return config('correction.reasons')[$this->reason] ?? $this->reason;
    }

    public function getEditableFieldsAttribute(): array
    {
        return config('correction.reason_fields')[$this->reason] ?? [];
    }

    public function needsNameUpdate(): bool
    {
        return in_array('full_name', $this->editable_fields, true);
    }

    public function needsDobUpdate(): bool
    {
        return in_array('date_of_birth', $this->editable_fields, true);
    }

    public function isValid(): bool
    {
        return $this->status === 'approved'
            && $this->token
            && $this->token_expires_at
            && now()->lessThan($this->token_expires_at)
            && is_null($this->token_used_at);
    }
}
