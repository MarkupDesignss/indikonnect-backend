<?php

namespace App\Services\Commission;

interface CommissionServiceInterface
{
    // Order Posting
    public function postOrderEvent(OrderPayload $payload): EventResponse;
    
    // Reversal
    public function postReversalEvent(ReversalPayload $payload): EventResponse;
    
    // Commission Visibility
    public function getCommission(int $userId, string $period = 'current'): array;
    public function getRank(int $userId): array;
    public function getBonus(int $userId, string $period = 'current'): array;
    public function getCoins(int $userId): int;
    public function getLedger(int $userId, string $period = 'current'): array;
    
    // NEW METHODS
    public function getCV(int $userId): array;
    public function getDownline(int $userId): array;
    
    // Health Check
    public function healthCheck(): bool;
    public function getPayoutRunData(string $period): array;
}