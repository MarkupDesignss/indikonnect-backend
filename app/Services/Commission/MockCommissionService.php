<?php

namespace App\Services\Commission;

use Illuminate\Support\Facades\Log;

class MockCommissionService implements CommissionServiceInterface
{
    protected int $failureRate;
    protected int $latencyMs;

    public function __construct()
    {
        $this->failureRate = (int) config('commission.mock_failure_rate', 30);
        $this->latencyMs   = (int) config('commission.mock_latency_ms', 500);
    }

    public function postOrderEvent(OrderPayload $payload): EventResponse
    {
        if ($this->latencyMs > 0) usleep($this->latencyMs * 1000);

        if (rand(1, 100) <= $this->failureRate) {
            Log::warning('Mock Commission API failed', ['order' => $payload->orderReference]);
            return new EventResponse(false, 'Mock API simulated failure', ['retryable' => true]);
        }

        $mockCv = round($payload->totalOrderValue * 0.10, 2);
        $breakdown = array_map(fn($line) => [
            'product_id' => $line['productIdentifier'],
            'cv' => round(($line['quantity'] * $line['unitPriceCharged']) * 0.10, 2),
        ], $payload->lines);

        Log::info('Mock Commission API success', ['order' => $payload->orderReference, 'cv' => $mockCv]);

        return new EventResponse(true, 'Order event posted (MOCK)', [
            'cv' => $mockCv,
            'cv_breakdown' => $breakdown,
        ]);
    }

    public function postReversalEvent(ReversalPayload $payload): EventResponse
    {
        usleep($this->latencyMs * 1000);
        if (rand(1, 100) <= $this->failureRate) {
            return new EventResponse(false, 'Mock reversal failed', ['retryable' => true]);
        }
        return new EventResponse(true, 'Reversal posted (MOCK)', ['reversed_cv' => $payload->originalCv]);
    }

    public function healthCheck(): bool
    {
        return rand(1, 100) > 10;
    }
}