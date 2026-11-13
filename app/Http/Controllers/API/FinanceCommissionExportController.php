<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class FinanceCommissionExportController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    // ============================================================
    // REPORT #1: Commission Sheet Download (TDS sheet)
    // ============================================================
    /**
     * GET /api/finance/commission-export?week=42&year=2026
     *
     * TIP: Use week=0 to see the full Ignite Sales Calendar for debugging
     */
    public function download(Request $request)
    {
        $request->validate([
            'week' => 'required|integer|min:0|max:53',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $week = (int) $request->week;
        $year = (int) $request->year;

        // 🔍 DEBUG MODE: Show entire Ignite calendar
        if ($week === 0) {
            $calendar = $this->ignite->salesCalendar($year);
            return response()->json([
                'success'  => true,
                'message'  => 'Full Ignite Sales Calendar for ' . $year,
                'calendar' => $calendar['data'] ?? [],
            ]);
        }

        [$weekStart, $weekEnd] = $this->getWeekRange($week, $year);

        Log::info("Commission export started", [
            'week'       => $week,
            'year'       => $year,
            'week_start' => $weekStart->toDateTimeString(),
            'week_end'   => $weekEnd->toDateTimeString(),
        ]);

        // ✅ complete_pan_card added to select
        $distributors = User::query()
            ->with(['distributorProfile'])
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select('id', 'full_name', 'email', 'distributor_id', 'complete_pan_card')
            ->orderBy('id')
            ->get();

        $rows   = [];
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

                $tdsAmount     = round($grossCommission * 0.02, 2);
                $netCommission = round($grossCommission - $tdsAmount, 2);

                $profile = $dist->distributorProfile;

                $rows[] = [
                    'ba_id'               => $dist->distributor_id,
                    'distributor_name'    => $dist->full_name,
                    'email'               => $dist->email,
                    'week'                => $week,
                    'year'                => $year,
                    'gross_commission'    => $grossCommission,
                    'tds_amount'          => $tdsAmount,
                    'net_commission'      => $netCommission,

                    // Bank details from distributor_profiles — complete
                    'bank_holder_name'    => $profile->bank_holder_name ?? '-',
                    'bank_account_number' => $profile->complete_bank_account ?? '-',
                    'bank_ifsc'           => $profile->bank_ifsc ?? '-',

                    // GST masked from distributor_profiles
                    'gst_in'              => $this->maskGst($profile->gst_in ?? null),

                    // PAN masked from users
                    'pan_number'          => $this->maskPan($dist->complete_pan_card ?? null),
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

    // ============================================================
    // REPORT #2: Weekly Commission Report
    // ============================================================
    /**
     * GET /api/finance/weekly-commission-report?year=2026&weeks=12&ba_id=optional
     */
    public function weeklyCommissionReport(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'weeks' => 'nullable|integer|min:1|max:53',
            'ba_id' => 'nullable|string|max:100',
        ]);

        $year  = (int) $request->year;
        $weeks = min(max((int) $request->input('weeks', 12), 1), 53);
        $baId  = $request->input('ba_id');

        $calendar = $this->getRawCalendar($year);
        if (!$calendar) {
            return response()->json([
                'success' => false,
                'message' => "Sales calendar for {$year} not available",
            ], 422);
        }

        $weekWindows = $this->buildWeekWindows($calendar);
        if (empty($weekWindows)) {
            return response()->json([
                'success' => false,
                'message' => 'No weeks found in calendar',
            ], 422);
        }

        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $query = User::query()
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select('id', 'full_name', 'email', 'distributor_id')
            ->orderBy('id');

        if ($baId) {
            $query->where('distributor_id', $baId);
        }

        $distributors = $query->get();

        if ($distributors->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No distributors found',
            ], 404);
        }

        $cutoff = $weekWindows[0]['start'];

        $perDistributor = [];
        $failed         = [];

        foreach ($distributors as $dist) {
            try {
                $transactions = $this->fetchAllPointTxsSince($dist->distributor_id, $cutoff);
                $buckets      = $this->bucketByWeek($transactions, $weekWindows);

                $perDistributor[$dist->distributor_id] = [
                    'distributor' => $dist,
                    'buckets'     => $buckets,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'distributor_id' => $dist->distributor_id,
                    'error'          => $e->getMessage(),
                ];
                Log::warning('Weekly report failed for distributor', [
                    'ba_id' => $dist->distributor_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $weekRows = [];

        foreach (array_reverse($weekWindows) as $win) {
            $weekNo = $win['week_no'];

            $aggCashUsd  = 0;
            $aggGreen    = 0;
            $aggYellow   = 0;
            $aggBlue     = 0;
            $aggCvLeft   = 0;
            $aggCvRight  = 0;
            $txCount     = 0;
            $distributorsInWeek = [];

            foreach ($perDistributor as $distBaId => $entry) {
                $b = $entry['buckets'][$weekNo] ?? null;
                if (!$b) continue;

                $cash   = (float) ($b['cash_bal']   ?? 0);
                $green  = (float) ($b['green_bal']  ?? 0);
                $yellow = (float) ($b['yellow_bal'] ?? 0);
                $blue   = (float) ($b['blue_bal']   ?? 0);
                $cvL    = (float) ($b['pv1_bal']    ?? 0);
                $cvR    = (float) ($b['pv2_bal']    ?? 0);
                $tc     = (int)   ($b['tx_count']   ?? 0);

                if ($tc === 0) continue;

                $aggCashUsd += $cash;
                $aggGreen   += $green;
                $aggYellow  += $yellow;
                $aggBlue    += $blue;
                $aggCvLeft  += $cvL;
                $aggCvRight += $cvR;
                $txCount    += $tc;

                $distributorsInWeek[] = [
                    'ba_id'       => $distBaId,
                    'name'        => $entry['distributor']->full_name,
                    'cash_usd'    => round($cash, 4),
                    'cash_inr'    => round($cash * $usdRate, 2),
                    'green_coin'  => round($green, 4),
                    'yellow_coin' => round($yellow, 4),
                    'blue_coin'   => round($blue, 4),
                    'cv_left'     => round($cvL, 4),
                    'cv_right'    => round($cvR, 4),
                    'tx_count'    => $tc,
                ];
            }

            if ($txCount === 0 && empty($win['is_current'])) {
                continue;
            }

            $weekRows[] = [
                'week_no'            => $weekNo,
                'year'               => $year,
                'start'              => $win['start']->format('Y-m-d H:i:s'),
                'end'                => $win['end']->format('Y-m-d H:i:s'),
                'is_current'         => $win['is_current'],
                'is_settled'         => $win['is_settled'],

                'total_cash_usd'     => round($aggCashUsd, 4),
                'total_cash_inr'     => round($aggCashUsd * $usdRate, 2),
                'total_green_coin'   => round($aggGreen, 4),
                'total_yellow_coin'  => round($aggYellow, 4),
                'total_blue_coin'    => round($aggBlue, 4),
                'total_cv_left'      => round($aggCvLeft, 4),
                'total_cv_right'     => round($aggCvRight, 4),
                'total_tx_count'     => $txCount,
                'distributor_count'  => count($distributorsInWeek),

                'distributors'       => $distributorsInWeek,
            ];

            if (count($weekRows) >= $weeks) {
                break;
            }
        }

        return response()->json([
            'success'  => true,
            'message'  => empty($weekRows)
                ? 'No commission data found for the given filters.'
                : 'Weekly commission report fetched successfully.',
            'currency' => [
                'base'          => 'USD',
                'display'       => 'INR',
                'exchange_rate' => $usdRate,
            ],
            'filters' => [
                'year'  => $year,
                'weeks' => $weeks,
                'ba_id' => $baId,
            ],
            'summary' => [
                'total_distributors' => $distributors->count(),
                'weeks_returned'     => count($weekRows),
                'failed_count'       => count($failed),
            ],
            'weeks'  => $weekRows,
            'failed' => $failed,
        ]);
    }

    // ============================================================
    // MASK HELPERS
    // ============================================================

    /**
     * Mask GST: first 2 + last 3 visible.
     * Example: 27AABCU9603R1ZM → 27**********1ZM
     */
    private function maskGst(?string $gst): string
    {
        if (empty($gst)) {
            return '-';
        }

        $gst = strtoupper(trim($gst));
        $len = strlen($gst);

        if ($len <= 5) {
            return str_repeat('*', $len);
        }

        return substr($gst, 0, 2)
            . str_repeat('*', $len - 5)
            . substr($gst, -3);
    }

    /**
     * Mask PAN: first 2 + last 3 visible.
     * Example: ABCDE1234F → AB*****34F
     */
    private function maskPan(?string $pan): string
    {
        if (empty($pan)) {
            return '-';
        }

        $pan = strtoupper(trim($pan));
        $len = strlen($pan);

        // PAN is 10 chars (5 letters + 4 digits + 1 letter)
        if ($len !== 10) {
            return str_repeat('*', $len);
        }

        return substr($pan, 0, 2)
            . str_repeat('*', 5)
            . substr($pan, -3);
    }

    // ============================================================
    // CALENDAR HELPERS
    // ============================================================

    /**
     * Exact week boundaries from Ignite Sales Calendar (IST).
     * Uses start_date + 00:00 UTC+8 → IST (matches dashboard).
     */
    private function getWeekRange(int $week, int $year): array
    {
        $calendar = Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {
            $resp = $this->ignite->salesCalendar($year);
            return ($resp['code'] ?? null) === ResultCode::OK->value ? $resp['data'] : null;
        });

        if (!$calendar) {
            throw new \Exception("Could not fetch Ignite Sales Calendar for year {$year}");
        }

        foreach (($calendar['months'] ?? []) as $month) {
            foreach (($month['weeks'] ?? []) as $w) {
                if ((int) ($w['week_no'] ?? 0) === $week) {
                    $startIst = Carbon::parse(($w['start_date'] ?? '') . ' 00:00:00', 'Asia/Hong_Kong')
                        ->setTimezone('Asia/Kolkata');

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
     * Raw sales calendar (cached 6h).
     */
    private function getRawCalendar(int $year): ?array
    {
        return Cache::remember("ignite_calendar_raw_{$year}", 21600, function () use ($year) {
            try {
                $resp = $this->ignite->salesCalendar($year);
                return ($resp['code'] ?? null) === ResultCode::OK->value
                    ? ($resp['data'] ?? null)
                    : null;
            } catch (\Throwable $e) {
                Log::warning('Ignite salesCalendar failed', ['year' => $year, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Calendar → IST week windows.
     */
    private function buildWeekWindows(array $calendar): array
    {
        $windows = [];

        foreach (($calendar['months'] ?? []) as $month) {
            foreach (($month['weeks'] ?? []) as $w) {
                if (empty($w['start_date']) || empty($w['end_date'])) {
                    continue;
                }

                $startIst = Carbon::parse($w['start_date'] . ' 00:00:00', 'Asia/Hong_Kong')
                    ->setTimezone('Asia/Kolkata');

                $endIst = Carbon::parse($w['end_date'] . ' 00:00:00', 'Asia/Hong_Kong')
                    ->addDay()
                    ->setTimezone('Asia/Kolkata')
                    ->subSecond();

                $windows[] = [
                    'week_no'    => (int) $w['week_no'],
                    'start'      => $startIst,
                    'end'        => $endIst,
                    'is_current' => (bool) ($w['is_current'] ?? false),
                    'is_settled' => (bool) ($w['is_settled'] ?? false),
                ];
            }
        }

        usort($windows, fn($a, $b) => $a['week_no'] <=> $b['week_no']);
        return $windows;
    }

    // ============================================================
    // TRANSACTION FETCH HELPERS
    // ============================================================

    /**
     * Fetch all pointTxs within a week (IST boundaries).
     */
    private function fetchHistoryForWeek(string $baId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $afterId   = 0;
        $limit     = 500;
        $guard     = 0;
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
            if (empty($rawRows)) break;

            foreach ($rawRows as $row) {
                $created = $row['created_at'] ?? null;
                if (!$created) continue;

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

    /**
     * Fetch ALL pointTxs since a cutoff date (early-break optimization).
     */
    private function fetchAllPointTxsSince(string $baId, Carbon $cutoff, int $maxPages = 200): array
    {
        $all     = [];
        $afterId = 0;
        $guard   = 0;

        do {
            try {
                $resp = $this->ignite->pointTxs($baId, $afterId, 500);
            } catch (\Throwable $e) {
                Log::warning('pointTxs exception', ['ba_id' => $baId, 'error' => $e->getMessage()]);
                break;
            }

            if (($resp['code'] ?? null) !== ResultCode::OK->value) {
                break;
            }

            $batch = $resp['data']['rows'] ?? [];
            if (empty($batch)) break;

            $shouldBreak = false;

            foreach ($batch as $row) {
                $created = $row['created_at'] ?? null;
                if (!$created) continue;

                $dt = Carbon::parse($created, 'Asia/Hong_Kong')
                    ->setTimezone('Asia/Kolkata');

                if ($dt->lt($cutoff)) {
                    $shouldBreak = true;
                    break;
                }

                $all[] = $row;
            }

            if ($shouldBreak) break;

            $afterId = (int) end($batch)['id'];
            $guard++;
        } while (!empty($resp['data']['has_more']) && $guard < $maxPages);

        return $all;
    }

    /**
     * Normalize a pointTx row.
     */
    private function normalizeRow(array $row): array
    {
        $usdRate = (float) \App\Models\Setting::where('group', 'exchange_rate')
            ->where('key', 'usd_to_inr')->value('value') ?: 0;

        $amount    = (float) ($row['amount'] ?? 0);
        $pointType = $row['point_type'] ?? '';
        $isCash    = $pointType === 'cash_bal';

        $amountInr = $isCash ? round($amount * $usdRate, 2) : 0;

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

    /**
     * Bucket transactions into weeks.
     */
    private function bucketByWeek(array $transactions, array $weekWindows): array
    {
        $buckets = [];

        foreach ($transactions as $tx) {
            $created = $tx['created_at'] ?? null;
            if (!$created) continue;

            try {
                $dt = Carbon::parse($created, 'Asia/Hong_Kong')
                    ->setTimezone('Asia/Kolkata');
            } catch (\Throwable $e) {
                continue;
            }

            $matchedWeek = null;
            foreach ($weekWindows as $win) {
                if ($dt->gte($win['start']) && $dt->lte($win['end'])) {
                    $matchedWeek = $win['week_no'];
                    break;
                }
            }

            if ($matchedWeek === null) continue;

            if (!isset($buckets[$matchedWeek])) {
                $buckets[$matchedWeek] = ['tx_count' => 0];
            }

            $buckets[$matchedWeek]['tx_count']++;

            $point = $tx['point_type'] ?? '';
            $amt   = (float) ($tx['amount'] ?? 0);

            if (in_array($point, ['cash_bal','green_bal','yellow_bal','blue_bal','pv1_bal','pv2_bal'], true)) {
                $buckets[$matchedWeek][$point] = ($buckets[$matchedWeek][$point] ?? 0) + $amt;
            }
        }

        return $buckets;
    }
}