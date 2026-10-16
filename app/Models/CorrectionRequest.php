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
        'token_expires_at',
        'token_used_at',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'token_expires_at' => 'datetime',
        'token_used_at'    => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->status === 'approved'
            && $this->token_used_at === null
            && $this->token_expires_at
            && $this->token_expires_at->isFuture();
    }

    public function getReasonLabelAttribute(): string
    {
        return config('correction.reasons')[$this->reason] ?? $this->reason;
    }
}
