<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionApiEvent extends Model
{
    protected $table = 'commission_api_events';

    protected $fillable = [
        'event_type', 'order_id', 'payload', 'status',
        'retry_count', 'max_retries', 'last_attempt',
        'error_message', 'response_data'
    ];

    protected $casts = [
        'payload' => 'array',
        'response_data' => 'array',
        'last_attempt' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shouldRetry(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }
        if ($this->retry_count >= $this->max_retries) {
            return false;
        }
        if (is_null($this->last_attempt)) {
            return true;
        }
        $backoffMinutes = pow(2, $this->retry_count);
        return now()->gte($this->last_attempt->addMinutes($backoffMinutes));
    }

    public function markProcessing(): void
    {
       // $this->status = 'processing';
        $this->last_attempt = now();
        $this->save();
    }

    public function markSent(array $response = null): void
    {
        $this->status = 'sent';
        $this->response_data = $response;
        $this->save();
    }

    public function markFailed(string $error): void
    {
        $this->retry_count++;
        $this->error_message = $error;
        $this->last_attempt = now();

        if ($this->retry_count >= $this->max_retries) {
            $this->status = 'failed';
        }
        $this->save();
    }
}