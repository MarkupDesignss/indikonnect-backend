<?php

namespace App\Services;

use App\Services\Commission\CommissionServiceInterface;

class DistributorService
{
    protected CommissionServiceInterface $commissionService;

    public function __construct(CommissionServiceInterface $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    /**
     * Fetch all data needed for the distributor dashboard.
     */
    public function getDashboardData(int $userId): array
    {
        return [
            'rank' => $this->commissionService->getRank($userId),
            'commission' => $this->commissionService->getCommission($userId),
            'bonus' => $this->commissionService->getBonus($userId),
            'coins' => $this->commissionService->getCoins($userId),
            'ledger' => $this->commissionService->getLedger($userId),
        ];
    }

    /**
     * Get the full commission ledger for a period.
     */
    public function getLedger(int $userId, string $period = 'current'): array
    {
        return $this->commissionService->getLedger($userId, $period);
    }

    /**
     * Get current coin balance.
     */
    public function getCoins(int $userId): int
    {
        return $this->commissionService->getCoins($userId);
    }
}