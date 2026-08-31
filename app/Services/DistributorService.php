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
    // public function getDashboardData(int $userId): array
    // {
    //     return [
    //         'rank' => $this->commissionService->getRank($userId),
    //         'commission' => $this->commissionService->getCommission($userId),
    //         'bonus' => $this->commissionService->getBonus($userId),
    //         'coins' => $this->commissionService->getCoins($userId),
    //         'ledger' => $this->commissionService->getLedger($userId),
    //     ];
    // }

    public function getDashboardData($userId)
    {
        $distributor = Distributor::where('user_id', $userId)->first();
        
        // Existing data
        $rankData = $this->getRankData($distributor);
        $commissionData = $this->getCommissionData($distributor);
        $bonusData = $this->getBonusData($distributor);
        $coins = $this->getCoinBalance($distributor);
        $ledger = $this->getLedgerEntries($distributor);
        
        // NEW DATA TO ADD
        $cvData = $this->getCommissionableVolume($distributor);  // With left/right legs
        $payoutData = $this->getPendingPayout($distributor);
        $teamData = $this->getTeamGrowth($distributor);
        $kycData = $this->getKycStatus($distributor);
        $periodInfo = $this->getCurrentPeriodInfo();
        
        return [
            // Existing
            'rank' => $rankData,
            'commission' => $commissionData,
            'bonus' => $bonusData,
            'coins' => $coins,
            'ledger' => $ledger,
            
            // NEW FIELDS
            'commissionable_volume' => $cvData,
            'payout' => $payoutData,
            'team' => $teamData,
            'kyc' => $kycData,
            'period_info' => $periodInfo,
        ];
    }

    // New methods to add:

    private function getCommissionableVolume($distributor)
    {
        // Fetch from Commission API
        $cvData = $this->commissionApi->getCV($distributor->distributor_id);
        
        return [
            'total' => $cvData['total'] ?? 0,
            'left_leg' => $cvData['left_leg'] ?? 0,
            'right_leg' => $cvData['right_leg'] ?? 0,
            'weaker_leg' => min($cvData['left_leg'] ?? 0, $cvData['right_leg'] ?? 0),
            'target' => $cvData['target'] ?? 0,
            'progress' => $cvData['progress'] ?? '0%',
            'period' => $cvData['period'] ?? date('Y-m'),
        ];
    }

    private function getPendingPayout($distributor)
    {
        // Sum of all pending commission entries
        $pending = CommissionEntry::where('distributor_id', $distributor->id)
            ->where('status', 'pending')
            ->sum('net_amount');
        
        $releasedThisPeriod = CommissionEntry::where('distributor_id', $distributor->id)
            ->where('status', 'released')
            ->whereMonth('created_at', now()->month)
            ->sum('net_amount');
        
        return [
            'pending' => $pending,
            'released_this_period' => $releasedThisPeriod,
            'total_released' => CommissionEntry::where('distributor_id', $distributor->id)
                ->where('status', 'released')
                ->sum('net_amount'),
            'currency' => 'INR',
        ];
    }

    private function getTeamGrowth($distributor)
    {
        // Fetch from Genealogy/Commission API
        $downline = $this->commissionApi->getDownline($distributor->distributor_id);
        
        return [
            'total_downline' => $downline['total'] ?? 0,
            'left_leg' => $downline['left_leg_count'] ?? 0,
            'right_leg' => $downline['right_leg_count'] ?? 0,
            'new_this_month' => $downline['new_this_month'] ?? 0,
            'active' => $downline['active'] ?? 0,
            'inactive' => $downline['inactive'] ?? 0,
        ];
    }

    private function getKycStatus($distributor)
    {
        $kyc = KYC::where('distributor_id', $distributor->id)->first();
        
        return [
            'status' => $kyc->status ?? 'pending',
            'verified_at' => $kyc->verified_at ?? null,
            'pan_verified' => $kyc->pan_verified ?? false,
            'aadhaar_verified' => $kyc->aadhaar_verified ?? false,
            'bank_verified' => $kyc->bank_verified ?? false,
        ];
    }

    private function getCurrentPeriodInfo()
    {
        $now = now();
        $period = $now->format('Y-m');
        $startDate = $now->copy()->startOfMonth();
        $endDate = $now->copy()->endOfMonth();
        
        return [
            'current_period' => $period,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_remaining' => $now->diffInDays($endDate),
            'period_progress' => round(($now->day / $endDate->day) * 100) . '%',
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