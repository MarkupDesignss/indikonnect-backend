<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FinanceCommissionExportController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * GET /api/finance/commission-export?week=42&year=2026
     * 
     * TIP: Use week=0 to see the full Ignite Sales Calendar for debugging
     */
    public function download(Request $request)
    {
        $request->validate([
            'week' => 'required|integer|min:0|max:53', // 0 = debug calendar
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $week = (int) $request->week;
        $year = (int) $request->year;

        // 🔍 DEBUG MODE: Show entire Ignite calendar
        if ($week === 0) {
            $calendar = $this->ignite->salesCalendar($year);
            return response()->json([
                'success' => true,
                'message' => 'Full Ignite Sales Calendar for ' . $year,
                'calendar' => $calendar['data'] ?? [],
            ]);
        }

        // Get exact week boundaries from Ignite Calendar
        [$weekStart, $weekEnd] = $this->getWeekRange($week, $year);

        Log::info("Commission export started", [
            'week' => $week,
            'year' => $year,
            'week_start' => $weekStart->toDateTimeString(),
            'week_end' => $weekEnd->toDateTimeString(),
        ]);

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
                $history = $this->fetchHistoryForWeek($dist->distributor_id, $weekStart, $weekEnd);

                $grossCommission = 0;

                foreach ($history as $tx) {
                    if (($tx['point_type'] ?? '') === 'cash_bal') {
                        $grossCommission += (float) $tx['amount_inr'];
                    }
                }

                if ($grossCommission <= 0) {
                    continue;
                }

                $tdsAmount = round($grossCommission * 0.02, 2);
                $netCommission = round($grossCommission - $tdsAmount, 2);

                $profile = $dist->distributorProfile;

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
                    'bank_account_number'=> $profile->encrypted_bank_account ?? '-',
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
            'week_range' => [
                'start' => $weekStart->format('Y-m-d H:i:s'),
                'end'   => $weekEnd->format('Y-m-d H:i:s'),
            ],
            'summary' => [
                'total_distributors' => $distributors->count(),
                'total_eligible'     => count($rows),
                'failed_count'       => count($failed),
            ],
            'data'   => $rows,
            'failed' => $failed,
        ]);
    }

    /**
     * Get exact week boundaries from Ignite Sales Calendar API
     * Returns [weekStart (IST), weekEnd (IST, inclusive)] ready for date comparison.
     */
    // private function getWeekRange(int $week, int $year): array
    // {
    //     // Fetch calendar once per year (cache for 24 hours)
    //     $calendar = \Illuminate\Support\Facades\Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {
    //         $resp = $this->ignite->salesCalendar($year);
    //         return ($resp['code'] ?? null) === ResultCode::OK->value ? $resp['data'] : null;
    //     });

    //     if (!$calendar) {
    //         throw new \Exception("Could not fetch Ignite Sales Calendar for year {$year}");
    //     }

    //     $months = $calendar['months'] ?? [];

    //     foreach ($months as $month) {
    //         foreach (($month['weeks'] ?? []) as $w) {
    //             if ((int) ($w['week_no'] ?? 0) === $week) {
    //                 // Ignite returns UTC+8. Convert to IST (Asia/Kolkata).
    //                 $startIst = Carbon::parse($w['start_at'], 'Asia/Hong_Kong')
    //                     ->setTimezone('Asia/Kolkata');

    //                 // end_at is exclusive → subtract 1 second for inclusive end
    //                 $endIst = Carbon::parse($w['end_at'], 'Asia/Hong_Kong')
    //                     ->setTimezone('Asia/Kolkata')
    //                     ->subSecond();

    //                 return [$startIst, $endIst];
    //             }
    //         }
    //     }

    //     throw new \Exception("Week {$week} not found in Ignite Calendar for year {$year}");
    // }

    private function getWeekRange(int $week, int $year): array
    {
        $calendar = \Illuminate\Support\Facades\Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {
            $resp = $this->ignite->salesCalendar($year);
            return ($resp['code'] ?? null) === ResultCode::OK->value ? $resp['data'] : null;
        });

        if (!$calendar) {
            throw new \Exception("Could not fetch Ignite Sales Calendar for year {$year}");
        }

        foreach (($calendar['months'] ?? []) as $month) {
            foreach (($month['weeks'] ?? []) as $w) {
                if ((int) ($w['week_no'] ?? 0) === $week) {

                    // Same formula as dashboard: start_date + 00:00 UTC+8 → IST
                    $startIst = Carbon::parse(($w['start_date'] ?? '') . ' 00:00:00', 'Asia/Hong_Kong')
                        ->setTimezone('Asia/Kolkata');

                    // end_date + 1 day − 1 sec → IST (inclusive end)
                    $endIst = Carbon::parse(($w['end_date'] ?? '') . ' 00:00:00', 'Asia/Hong_Kong')
                        ->addDay()
                        ->setTimezone('Asia/Kolkata')
                        ->subSecond();

                    return [$startIst, $endIst];
                }
            }
        }

        throw new \Exception("Week {$week} not found in Ignite Calendar for year {$year}");
    }

   /**
     * Fetch all pointTxs for a member that fall within the given week (IST boundaries).
     * Handles pagination via after_id. Compares created_at in IST.
     */
    private function fetchHistoryForWeek(string $baId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $afterId  = 0;
        $limit    = 500;
        $guard    = 0;
        $collected = [];

        do {
            $resp = $this->ignite->pointTxs($baId, $afterId, $limit);

            if (($resp['code'] ?? null) !== ResultCode::OK->value) {
                Log::warning("Ignite pointTxs failed for {$baId}", [
                    'code' => $resp['code'] ?? null,
                    'msg'  => $resp['msg'] ?? null,
                ]);
                break;
            }

            $rawRows = $resp['data']['rows'] ?? [];
            if (empty($rawRows)) {
                break;
            }

            foreach ($rawRows as $row) {
                $created = $row['created_at'] ?? null;
                if (!$created) {
                    continue;
                }

                // Ignite returns created_at in UTC+8 with milliseconds → convert to IST
                $dt = Carbon::parse($created, 'Asia/Hong_Kong')
                    ->setTimezone('Asia/Kolkata');

                if ($dt->gte($weekStart) && $dt->lte($weekEnd)) {
                    $collected[] = $this->normalizeRow($row);
                }
            }

            $afterId = (int) end($rawRows)['id'];
            $guard++;
        } while (($resp['data']['has_more'] ?? false) && $guard < 200);

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

        // IST display for debugging
        $createdIst = $row['created_at']
            ? Carbon::parse($row['created_at'], 'Asia/Hong_Kong')
                ->setTimezone('Asia/Kolkata')
                ->toDateTimeString()
            : null;

        return [
            'id'          => $row['id'],
            'point_type'  => $pointType,
            'amount_usd'  => $amount,
            'amount_inr'  => $amountInr,
            'created_ist' => $createdIst,
        ];
    }
}