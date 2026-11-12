<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FinanceCommissionExportController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * GET /api/finance/commission-export?week=42&year=2026
     */
    public function download(Request $request)
    {
        $request->validate([
            'week' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $week = (int) $request->week;
        $year = (int) $request->year;

        // Fetch users with their distributor profiles for Bank Details
        $distributors = User::query()
            ->with(['distributorProfile'])
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select('id', 'full_name', 'email', 'distributor_id')
            ->orderBy('id')
            ->get();

        $rows = [];
        $failed = [];

        foreach ($distributors as $dist) {
            try {
                // Fetch all transactions for the week
                $history = $this->fetchHistoryForWeek($dist->distributor_id, $week, $year);

                $grossCommission = 0;

                // Calculate total cash commission only
                foreach ($history as $tx) {
                    if (($tx['point_type'] ?? '') === 'cash_bal') {
                        $grossCommission += (float) $tx['amount_inr'];
                    }
                }

                // Agar koi commission nahi mila, to skip karo
                if ($grossCommission <= 0) {
                    continue;
                }

                $tdsAmount = round($grossCommission * 0.02, 2);
                $netCommission = round($grossCommission - $tdsAmount, 2);

                $profile = $dist->distributorProfile;

                // Ek distributor ki ek hi row banegi
                $rows[] = [
                    'ba_id'              => $dist->distributor_id,
                    'distributor_name'   => $dist->full_name,
                    'email'              => $dist->email,
                    'week'               => $week,
                    'year'               => $year,
                    'gross_commission'   => $grossCommission,
                    'tds_amount'         => $tdsAmount,
                    'net_commission'     => $netCommission,
                    'bank_holder_name'   => $profile->bank_holder_name ?? '-',
                    'bank_account_number'=> $profile->encrypted_bank_account ?? '-', // Ensure it's decrypted
                    'bank_ifsc'          => $profile->bank_ifsc ?? '-',
                ];

            } catch (\Throwable $e) {
                $failed[] = [
                    'distributor_id' => $dist->distributor_id,
                    'error'          => $e->getMessage(),
                ];
                Log::warning('Commission export failed', [
                    'distributor_id' => $dist->distributor_id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        return response()->json([
            'success'  => true,
            'message'  => empty($rows)
                ? 'No commission data available for the specified week and year.'
                : 'Commission data fetched successfully.',
            'currency' => [
                'base'          => 'USD',
                'display'       => 'INR',
                'exchange_rate' => $usdRate,
            ],
            'filters' => [
                'week' => $week,
                'year' => $year,
            ],
            'summary' => [
                'total_distributors' => $distributors->count(),
                'total_eligible'     => count($rows), // Jinka commission > 0 hai
                'failed_count'       => count($failed),
            ],
            'data'   => $rows,
            'failed' => $failed,
        ]);
    }

    private function fetchHistoryForWeek(string $baId, int $week, int $year): array
    {
        $afterId = 0;
        $limit = 500;
        $guard = 0;
        $collected = [];

        do {
            $resp = $this->ignite->pointTxs($baId, $afterId, $limit);

            if (($resp['code'] ?? null) !== ResultCode::OK->value) {
                break;
            }

            $rawRows = $resp['data']['rows'] ?? [];
            if (empty($rawRows)) {
                break;
            }

            foreach ($rawRows as $row) {
                $created = $row['created_at'] ?? null;
                if ($created) {
                    // Convert Ignite time to local
                    $dt = \Carbon\Carbon::parse($created)->setTimezone('Asia/Kolkata');
                    if ((int) $dt->weekOfYear !== $week || (int) $dt->year !== $year) {
                        continue;
                    }
                }
                $collected[] = $this->normalizeRow($row);
            }

            $afterId = (int) end($rawRows)['id'];
            $guard++;
        } while (($resp['data']['has_more'] ?? false) && $guard < 100);

        return $collected;
    }

    private function normalizeRow(array $row): array
    {
        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $amount    = (float) ($row['amount'] ?? 0);
        $pointType = $row['point_type'] ?? '';
        $isCash    = $pointType === 'cash_bal';

        $amountInr = $isCash ? round($amount * $usdRate, 2) : 0;

        return [
            'id'             => $row['id'],
            'point_type'     => $pointType,
            'amount_usd'     => $amount,
            'amount_inr'     => $amountInr,
        ];
    }
}