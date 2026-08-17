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

    // -------- Order Posting --------

    public function postOrderEvent(OrderPayload $payload): EventResponse
    {
        if ($this->latencyMs > 0) usleep($this->latencyMs * 1000);

        if (rand(1, 100) <= $this->failureRate) {
            Log::warning('Mock order post failed', ['order' => $payload->orderReference]);
            return new EventResponse(false, 'Mock API simulated failure', ['retryable' => true]);
        }

        $mockCv = round($payload->totalOrderValue * 0.10, 2);
        Log::info('Mock order post success', ['order' => $payload->orderReference, 'cv' => $mockCv]);

        return new EventResponse(true, 'Order event posted (MOCK)', [
            'cv' => $mockCv,
            'cv_breakdown' => array_map(fn($line) => [
                'product_id' => $line['productIdentifier'],
                'cv' => round(($line['quantity'] * $line['unitPriceCharged']) * 0.10, 2),
            ], $payload->lines),
        ]);
    }

    // -------- Reversal / Clawback --------

    public function postReversalEvent(ReversalPayload $payload): EventResponse
    {
        if ($this->latencyMs > 0) usleep($this->latencyMs * 1000);

        if (rand(1, 100) <= $this->failureRate) {
            Log::warning('Mock reversal failed', ['order' => $payload->orderReference]);
            return new EventResponse(false, 'Mock reversal failed', ['retryable' => true]);
        }

        Log::info('Mock reversal success', ['order' => $payload->orderReference, 'cv' => $payload->originalCv]);
        return new EventResponse(true, 'Reversal posted (MOCK)', [
            'reversed_cv' => $payload->originalCv,
        ]);
    }

    // -------- Commission Visibility (Mock Data) --------

    public function getCommission(int $userId, string $period = 'current'): array
    {
        return [
            'period' => $period,
            'total' => 1500.00,
            'breakdown' => [
                ['type' => 'step_commission', 'amount' => 800.00],
                ['type' => 'bonus', 'amount' => 700.00],
            ],
            'currency' => 'INR',
        ];
    }

    public function getRank(int $userId): array
    {
        return [
            'current_rank' => 'Silver',
            'next_rank' => 'Gold',
            'progress_percentage' => 65,
            'requirements' => [
                'cv_required' => 10000,
                'cv_current' => 6500,
            ],
        ];
    }

    public function getBonus(int $userId, string $period = 'current'): array
    {
        return [
            'period' => $period,
            'bonuses' => [
                ['name' => 'Team Bonus', 'amount' => 500.00],
                ['name' => 'Leadership Bonus', 'amount' => 200.00],
            ],
            'total' => 700.00,
        ];
    }

    public function getCoins(int $userId): int
    {
        return 500;
    }

    public function getLedger(int $userId, string $period = 'current'): array
    {
        return [
            'entries' => [
                [
                    'date' => now()->subDays(1)->toDateString(),
                    'type' => 'commission',
                    'description' => 'Step commission for order #ORD-123',
                    'amount' => 300.00,
                    'status' => 'released',
                ],
                [
                    'date' => now()->subDays(3)->toDateString(),
                    'type' => 'bonus',
                    'description' => 'Team bonus',
                    'amount' => 200.00,
                    'status' => 'pending',
                ],
            ],
            'opening_balance' => 1000.00,
            'closing_balance' => 1500.00,
        ];
    }

    // -------- Health Check --------

    public function healthCheck(): bool
    {
        return rand(1, 100) > 10;
    }

    public function getPayoutRunData(string $period): array
    {
        // Get all distributors
        $distributors = \App\Models\User::where('account_type', 'distributor')->get();

        $entries = [];
        foreach ($distributors as $dist) {
            $gross = rand(1000, 50000); // Mock commission
            $tds = round($gross * 0.02, 2); // 2% TDS
            $entries[] = [
                'distributor_id' => $dist->id,
                'name' => $dist->name,
                'gross_commission' => $gross,
                'tds' => $tds,
                'net_payable' => $gross - $tds,
            ];
        }

        return [
            'period' => $period,
            'entries' => $entries,
            'total_gross' => array_sum(array_column($entries, 'gross_commission')),
            'total_tds' => array_sum(array_column($entries, 'tds')),
            'total_net' => array_sum(array_column($entries, 'net_payable')),
        ];
    }
}