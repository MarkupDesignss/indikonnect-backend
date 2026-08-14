<?php

namespace App\Services\Commission;

interface CommissionServiceInterface
{
    // Order posting
    public function postOrderEvent(OrderPayload $payload): EventResponse;

    // Reversal / clawback
    public function postReversalEvent(ReversalPayload $payload): EventResponse;

    // Health check
    public function healthCheck(): bool;

    // Commission visibility
    public function getCommission(int $userId, string $period = 'current'): array;
    public function getRank(int $userId): array;
    public function getBonus(int $userId, string $period = 'current'): array;
    public function getCoins(int $userId): int;
    public function getLedger(int $userId, string $period = 'current'): array;
}