<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionApiEvent extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'event_type',
        'order_id',
        'payload',
        'status',
        'retry_count',
        'max_retries',
        'last_attempt',
        'error_message',
        'response_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'payload' => 'array',
        'response_data' => 'array',
        'last_attempt' => 'datetime',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
    ];

    /**
     * Get the order associated with this event (if any).
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if event is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if event is sent.
     */
    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * Check if event is acknowledged.
     */
    public function isAcknowledged(): bool
    {
        return $this->status === 'acknowledged';
    }

    /**
     * Check if event is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if event is retrying.
     */
    public function isRetrying(): bool
    {
        return $this->status === 'retrying';
    }

    /**
     * Increment retry count.
     */
    public function incrementRetry(): void
    {
        $this->increment('retry_count');
        $this->last_attempt = now();
        $this->save();
    }

    /**
     * Mark event as acknowledged.
     */
    public function markAcknowledged(array $response): void
    {
        $this->status = 'acknowledged';
        $this->response_data = $response;
        $this->save();
    }

    /**
     * Mark event as failed with error message.
     */
    public function markFailed(string $error): void
    {
        $this->status = 'failed';
        $this->error_message = $error;
        $this->save();
    }

    /**
     * Mark event for retry.
     */
    public function markRetrying(): void
    {
        $this->status = 'retrying';
        $this->last_attempt = now();
        $this->save();
    }

    /**
     * Reset event to pending for replay.
     */
    public function resetToPending(): void
    {
        $this->status = 'pending';
        $this->retry_count = 0;
        $this->error_message = null;
        $this->response_data = null;
        $this->last_attempt = null;
        $this->save();
    }

    /**
     * Scope: pending events (ready to send).
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: failed events.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: events by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }
}