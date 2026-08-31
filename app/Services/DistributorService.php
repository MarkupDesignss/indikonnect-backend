<?php

namespace App\Services;

use App\Models\User;
use App\Models\DistributorProfile;
use App\Models\CommissionLedger;
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
    public function getDashboardData($userId)
    {
        // Get distributor profile from distributor_profiles table
        $distributor = DistributorProfile::where('user_id', $userId)->first();
        
        if (!$distributor) {
            return null;
        }

        // Get user data from users table
        $user = User::find($userId);

        // Existing data
        $rankData = $this->getRankData($userId);
        $commissionData = $this->getCommissionData($userId);
        $bonusData = $this->getBonusData($userId);
        $coins = $this->getCoinBalance($userId);
        $ledger = $this->getLedgerEntries($userId);
        
        // NEW DATA TO ADD
        $cvData = $this->getCommissionableVolume($userId);
        $payoutData = $this->getPendingPayout($userId);
        $teamData = $this->getTeamGrowth($userId);
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

    /**
     * Get rank data
     */
    private function getRankData($userId)
    {
        // Use commissionService
        $rankData = $this->commissionService->getRank($userId);
        
        return [
            'current_rank' => $rankData['current_rank'] ?? 'Associate',
            'next_rank' => $rankData['next_rank'] ?? 'Silver',
            'progress_percentage' => $rankData['progress_percentage'] ?? 0,
            'requirements' => [
                'cv_required' => $rankData['cv_required'] ?? 0,
                'cv_current' => $rankData['cv_current'] ?? 0,
            ],
        ];
    }

    /**
     * Get Commissionable Volume with left/right legs
     */
    private function getCommissionableVolume($userId)
    {
        // Use commissionService
        $cvData = $this->commissionService->getCV($userId);
        
        return [
            'total' => $cvData['total'] ?? 0,
            'left_leg' => $cvData['left_leg'] ?? 0,
            'right_leg' => $cvData['right_leg'] ?? 0,
            'weaker_leg' => min($cvData['left_leg'] ?? 0, $cvData['right_leg'] ?? 0),
            'target' => $cvData['target'] ?? 100000,
            'progress' => $cvData['progress'] ?? '0%',
            'period' => $cvData['period'] ?? date('Y-m'),
        ];
    }

    /**
     * Get commission data
     */
    private function getCommissionData($userId)
    {
        $commission = $this->commissionService->getCommission($userId);
        
        return [
            'period' => 'current',
            'total' => $commission['total'] ?? 0,
            'breakdown' => $commission['breakdown'] ?? [],
            'currency' => 'INR',
        ];
    }

    /**
     * Get bonus data
     */
    private function getBonusData($userId)
    {
        $bonus = $this->commissionService->getBonus($userId);
        
        return [
            'period' => 'current',
            'bonuses' => $bonus['items'] ?? [],
            'total' => $bonus['total'] ?? 0,
        ];
    }

    /**
     * Get pending payout from commission_ledger table
     */
    private function getPendingPayout($userId)
    {
        // Get distributor profile first to get distributor_id
        $distributor = DistributorProfile::where('user_id', $userId)->first();
        
        if (!$distributor) {
            return [
                'pending' => 0,
                'released_this_period' => 0,
                'total_released' => 0,
                'currency' => 'INR',
            ];
        }

        // Sum of pending commission entries from commission_ledger
        $pending = CommissionLedger::where('distributor_id', $distributor->id)
            ->where('status', 'pending')
            ->sum('net_amount');
        
        $releasedThisPeriod = CommissionLedger::where('distributor_id', $distributor->id)
            ->where('status', 'released')
            ->whereMonth('created_at', now()->month)
            ->sum('net_amount');
        
        $totalReleased = CommissionLedger::where('distributor_id', $distributor->id)
            ->where('status', 'released')
            ->sum('net_amount');
        
        return [
            'pending' => (float) $pending,
            'released_this_period' => (float) $releasedThisPeriod,
            'total_released' => (float) $totalReleased,
            'currency' => 'INR',
        ];
    }

    /**
     * Get team growth from genealogy_placements table
     */
    private function getTeamGrowth($userId)
    {
        // Use commissionService or query genealogy_placements
        $downline = $this->commissionService->getDownline($userId);
        
        return [
            'total_downline' => $downline['total'] ?? 0,
            'left_leg' => $downline['left_leg_count'] ?? 0,
            'right_leg' => $downline['right_leg_count'] ?? 0,
            'new_this_month' => $downline['new_this_month'] ?? 0,
            'active' => $downline['active'] ?? 0,
            'inactive' => $downline['inactive'] ?? 0,
        ];
    }

    /**
     * Get KYC status from distributor_profiles table
     */
    private function getKycStatus($distributor)
    {
        return [
            'status' => $distributor->kyc_status ?? 'pending',
            'application_status' => $distributor->application_status ?? 'draft',
            'verified_at' => $distributor->aadhaar_verified_at ?? null,
            'aadhaar_verified' => $distributor->aadhaar_verified ?? false,
            'pan_verified' => $distributor->pan_verified ?? false,
            'bank_verified' => $distributor->bank_verified ?? false,
            'submitted_at' => $distributor->submitted_at ?? null,
            'reviewed_at' => $distributor->reviewed_at ?? null,
        ];
    }

    /**
     * Get current period info
     */
    private function getCurrentPeriodInfo()
    {
        $now = now();
        $startDate = $now->copy()->startOfMonth();
        $endDate = $now->copy()->endOfMonth();
        
        return [
            'current_period' => $now->format('Y-m'),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_remaining' => (int) $now->diffInDays($endDate),
            'period_progress' => round(($now->day / $endDate->day) * 100) . '%',
        ];
    }

    /**
     * Get coin balance
     */
    private function getCoinBalance($userId)
    {
        return $this->commissionService->getCoins($userId);
    }

    /**
     * Get ledger entries from commission_ledger table
     */
    private function getLedgerEntries($userId)
    {
        // Get distributor profile
        $distributor = DistributorProfile::where('user_id', $userId)->first();
        
        if (!$distributor) {
            return [
                'entries' => [],
                'opening_balance' => 0,
                'closing_balance' => 0,
            ];
        }

        // Get entries from commission_ledger
        $entries = CommissionLedger::where('distributor_id', $distributor->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        
        $openingBalance = CommissionLedger::where('distributor_id', $distributor->id)
            ->where('status', 'released')
            ->sum('net_amount');
        
        $closingBalance = CommissionLedger::where('distributor_id', $distributor->id)
            ->sum('net_amount');
        
        return [
            'entries' => $entries->map(function ($entry) {
                return [
                    'date' => $entry->created_at ? $entry->created_at->format('Y-m-d') : null,
                    'type' => $entry->entry_type,
                    'description' => $this->getEntryDescription($entry),
                    'amount' => (float) $entry->net_amount,
                    'gross' => (float) $entry->gross_amount,
                    'tds' => (float) $entry->tds_amount,
                    'status' => $entry->status,
                    'order_reference' => $entry->order_reference,
                    'period' => $entry->period,
                ];
            }),
            'opening_balance' => (float) $openingBalance,
            'closing_balance' => (float) $closingBalance,
        ];
    }

    /**
     * Helper: Get description for ledger entry
     */
    private function getEntryDescription($entry)
    {
        $descriptions = [
            'commission' => 'Commission for period ' . $entry->period,
            'bonus' => 'Bonus for period ' . $entry->period,
            'coin_award' => 'Coin award for period ' . $entry->period,
            'coin_redeemed' => 'Coins redeemed against order',
            'reversal' => 'Reversal for order ' . $entry->order_reference,
            'settlement' => 'Payout settlement for period ' . $entry->period,
        ];
        
        return $descriptions[$entry->entry_type] ?? $entry->entry_type;
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