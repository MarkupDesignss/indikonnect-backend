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
    // public function download(Request $request)
    // {
    //     $request->validate([
    //         'week' => 'required|integer|min:0|max:53',
    //         'year' => 'required|integer|min:2020|max:2100',
    //     ]);

    //     $week = (int) $request->week;
    //     $year = (int) $request->year;

    //     // DEBUG MODE: Show entire Ignite calendar
    //     // if ($week === 0) {
    //     //     $calendar = $this->ignite->salesCalendar($year);
    //     //     return response()->json([
    //     //         'success'  => true,
    //     //         'message'  => 'Full Ignite Sales Calendar for ' . $year,
    //     //         'calendar' => $calendar['data'] ?? [],
    //     //     ]);
    //     // }

    //     // DEBUG MODE: Show Ignite calendar converted to IST
    //     if ($week === 0) {
    //         $response = $this->ignite->salesCalendar($year);
    //         $calendar = $response['data'] ?? [];

    //         foreach ($calendar['months'] ?? [] as &$month) {
    //             foreach ($month['weeks'] ?? [] as &$w) {

    //                 if (!empty($w['start_at'])) {
    //                     $startIst = Carbon::parse(
    //                         $w['start_at'],
    //                         'Asia/Hong_Kong'
    //                     )->setTimezone('Asia/Kolkata');

    //                     $w['start_at'] = $startIst->format('Y-m-d H:i:s');
    //                     $w['start_date'] = $startIst->format('Y-m-d');
    //                 }

    //                 if (!empty($w['end_at'])) {
    //                     $endIst = Carbon::parse(
    //                         $w['end_at'],
    //                         'Asia/Hong_Kong'
    //                     )->setTimezone('Asia/Kolkata')->subSecond();

    //                     $w['end_at'] = $endIst->format('Y-m-d H:i:s');
    //                     $w['end_date'] = $endIst->format('Y-m-d');
    //                 }
    //             }
    //             unset($w);
    //         }
    //         unset($month);

    //         return response()->json([
    //             'success'  => true,
    //             'message'  => 'Ignite Sales Calendar converted to IST for ' . $year,
    //             'calendar' => $calendar,
    //         ]);
    //     }

    //     [$weekStart, $weekEnd] = $this->getWeekRange($week, $year);

    //     Log::info("Commission export started", [
    //         'week'       => $week,
    //         'year'       => $year,
    //         'week_start' => $weekStart->toDateTimeString(),
    //         'week_end'   => $weekEnd->toDateTimeString(),
    //     ]);

    //     $usdRate = (float) Cache::remember('usd_to_inr_rate', 3600, function () {
    //         return \App\Models\Setting::where('group', 'exchange_rate')
    //             ->where('key', 'usd_to_inr')->value('value') ?: 0;
    //     });

    //     if ($usdRate <= 0) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Exchange rate not configured. Contact admin.'
    //         ], 500);
    //     }

    //     $distributors = User::query()
    //         ->with(['distributorProfile'])
    //         ->where('account_type', 'distributor')
    //         ->whereNotNull('distributor_id')
    //         ->where('distributor_id', '!=', '')
    //         ->select('id', 'full_name', 'email', 'distributor_id', 'complete_pan_card')
    //         ->orderBy('id')
    //         ->get();

    //     $rows   = [];
    //     $failed = [];

    //     foreach ($distributors as $dist) {
    //         try {
    //             $history = $this->fetchHistoryForWeek($dist->distributor_id, $weekStart, $weekEnd, $usdRate);

    //             // ---------- Gross commission (withdrawals excluded) ----------
    //             $grossCommission = 0;

    //             foreach ($history as $tx) {
    //                 if (($tx['point_type'] ?? '') !== 'cash_bal') continue;

    //                 $desc   = $tx['description'] ?? '';
    //                 $amount = (float) $tx['amount_inr'];

    //                 // Withdrawals are payouts, not commission — exclude
    //                 if ($desc === 'wd_partner_payout' || str_starts_with($desc, 'wd_')) {
    //                     continue;
    //                 }

    //                 // Only positive earnings count
    //                 if ($amount > 0) {
    //                     $grossCommission += $amount;
    //                 }
    //             }

    //             if ($grossCommission <= 0) {
    //                 continue;
    //             }

    //             // ---------- TDS rate (based on complete_pan_card presence) ----------
    //             $profile     = $dist->distributorProfile;
    //             $gstIn       = $profile?->gst_in;
    //             $panVerified = (bool) ($profile?->pan_verified ?? false);

    //             $hasPanCard = !empty(trim((string) $dist->complete_pan_card))
    //                 && $dist->complete_pan_card !== '-';

    //             $tdsRate    = $hasPanCard ? 0.02 : 0.20;
    //             $tdsSection = $hasPanCard ? '194-O' : '194J';

    //             $tdsAmount     = round($grossCommission * $tdsRate, 2);
    //             $netCommission = round($grossCommission - $tdsAmount, 2);

    //             $rows[] = [
    //                 'ba_id'               => $dist->distributor_id,
    //                 'distributor_name'    => $dist->full_name,
    //                 'email'               => $dist->email,
    //                 'week'                => $week,
    //                 'year'                => $year,
    //                 'gross_commission'    => $grossCommission,

    //                 // TDS fields
    //                 'tds_section'         => $tdsSection,
    //                 'tds_rate_percent'    => round($tdsRate * 100, 2),
    //                 'tds_amount'          => $tdsAmount,
    //                 'net_commission'      => $netCommission,

    //                 // No masking — plain values
    //                 'gst_in'              => $gstIn ?: '-',
    //                 'pan_verified'        => $panVerified,

    //                 // Bank details
    //                 'bank_holder_name'    => $profile->bank_holder_name ?? '-',
    //                 'bank_ifsc'           => $profile->bank_ifsc ?? '-',

    //                 'pan_number'          => $dist->complete_pan_card ?? '-',
    //                 'bank_account_number' => $profile?->complete_bank_account ?? '-',

    //             ];
    //         } catch (\Throwable $e) {
    //             $failed[] = [
    //                 'distributor_id' => $dist->distributor_id,
    //                 'error'          => $e->getMessage(),
    //             ];
    //             Log::warning('Commission export failed', [
    //                 'distributor_id' => $dist->distributor_id,
    //                 'error'          => $e->getMessage(),
    //             ]);
    //         }
    //     }

    //     return response()->json([
    //         'success'  => true,
    //         'message'  => empty($rows)
    //             ? 'No commission data available for the specified week and year.'
    //             : 'Commission data fetched successfully.',
    //         'currency' => [
    //             'base'          => 'USD',
    //             'display'       => 'INR',
    //             'exchange_rate' => $usdRate,
    //         ],
    //         'filters' => [
    //             'week' => $week,
    //             'year' => $year,
    //         ],
    //         'week_range' => [
    //             'start' => $weekStart->format('Y-m-d H:i:s'),
    //             'end'   => $weekEnd->format('Y-m-d H:i:s'),
    //         ],
    //         'summary' => [
    //             'total_distributors' => $distributors->count(),
    //             'total_eligible'     => count($rows),
    //             'failed_count'       => count($failed),
    //             'total_gross'        => round(array_sum(array_column($rows, 'gross_commission')), 2),
    //             'total_tds'          => round(array_sum(array_column($rows, 'tds_amount')), 2),
    //             'total_net'          => round(array_sum(array_column($rows, 'net_commission')), 2),
    //         ],
    //         'data'   => $rows,
    //         'failed' => $failed,
    //     ]);
    // }

    public function download(Request $request)
    {
        $request->validate([
            'week' => 'required|integer|min:0|max:53',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $week = (int) $request->week;
        $year = (int) $request->year;

        // DEBUG MODE: Full Ignite calendar converted to IST
        if ($week === 0) {

            $response = $this->ignite->salesCalendar($year);
            $calendar = $response['data'] ?? [];

            foreach ($calendar['months'] ?? [] as &$month) {

                $monthStart = null;
                $monthEnd = null;

                foreach ($month['weeks'] ?? [] as &$w) {

                    [$startIst, $endIst] = $this->getIstWeekBoundaries($w);

                    $w['start_at'] = $startIst->format('Y-m-d H:i:s');
                    $w['end_at'] = $endIst->format('Y-m-d H:i:s');

                    $w['start_date'] = $startIst->format('Y-m-d');
                    $w['end_date'] = $endIst->format('Y-m-d');

                    if ($monthStart === null) {
                        $monthStart = $startIst->copy();
                    }

                    $monthEnd = $endIst->copy();
                }

                unset($w);

                if ($monthStart) {
                    $month['start_date'] = $monthStart->format('Y-m-d');
                }

                if ($monthEnd) {
                    $month['end_date'] = $monthEnd->format('Y-m-d');
                }
            }

            unset($month);

            return response()->json([
                'success'  => true,
                'message'  => 'Ignite Sales Calendar converted to IST for ' . $year,
                'calendar' => $calendar,
            ]);
        }

        // Get selected week boundaries in IST
        [$weekStart, $weekEnd] = $this->getWeekRange($week, $year);

        Log::info('Commission export started', [
            'week'       => $week,
            'year'        => $year,
            'week_start' => $weekStart->toDateTimeString(),
            'week_end'   => $weekEnd->toDateTimeString(),
        ]);

        $usdRate = (float) Cache::remember('usd_to_inr_rate', 3600, function () {
            return \App\Models\Setting::where('group', 'exchange_rate')
                ->where('key', 'usd_to_inr')
                ->value('value') ?: 0;
        });

        if ($usdRate <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Exchange rate not configured. Contact admin.'
            ], 500);
        }

        $distributors = User::query()
            ->with(['distributorProfile'])
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select(
                'id',
                'full_name',
                'email',
                'distributor_id',
                'complete_pan_card'
            )
            ->orderBy('id')
            ->get();

        $rows = [];
        $failed = [];

        foreach ($distributors as $dist) {

            try {

                $history = $this->fetchHistoryForWeek(
                    $dist->distributor_id,
                    $weekStart,
                    $weekEnd,
                    $usdRate
                );

                $grossCommission = 0;

                foreach ($history as $tx) {

                    if (($tx['point_type'] ?? '') !== 'cash_bal') {
                        continue;
                    }

                    $desc = $tx['description'] ?? '';
                    $amount = (float) $tx['amount_inr'];

                    if (
                        $desc === 'wd_partner_payout' ||
                        str_starts_with($desc, 'wd_')
                    ) {
                        continue;
                    }

                    if ($amount > 0) {
                        $grossCommission += $amount;
                    }
                }

                if ($grossCommission <= 0) {
                    continue;
                }

                $profile = $dist->distributorProfile;
                $gstIn = $profile?->gst_in;
                $panVerified = (bool) ($profile?->pan_verified ?? false);

                $hasPanCard = !empty(trim((string) $dist->complete_pan_card))
                    && $dist->complete_pan_card !== '-';

                $tdsRate = $hasPanCard ? 0.02 : 0.20;
                $tdsSection = $hasPanCard ? '194-O' : '194J';

                $tdsAmount = round($grossCommission * $tdsRate, 2);
                $netCommission = round($grossCommission - $tdsAmount, 2);

                $rows[] = [
                    'ba_id'               => $dist->distributor_id,
                    'distributor_name'    => $dist->full_name,
                    'email'               => $dist->email,
                    'week'                => $week,
                    'year'                => $year,
                    'gross_commission'    => $grossCommission,
                    'tds_section'         => $tdsSection,
                    'tds_rate_percent'    => round($tdsRate * 100, 2),
                    'tds_amount'          => $tdsAmount,
                    'net_commission'      => $netCommission,
                    'gst_in'              => $gstIn ?: '-',
                    'pan_verified'        => $panVerified,
                    'bank_holder_name'    => $profile->bank_holder_name ?? '-',
                    'bank_ifsc'           => $profile->bank_ifsc ?? '-',
                    'pan_number'          => $dist->complete_pan_card ?? '-',
                    'bank_account_number' => $profile?->complete_bank_account ?? '-',
                ];

            } catch (\Throwable $e) {

                $failed[] = [
                    'distributor_id' => $dist->distributor_id,
                    'error' => $e->getMessage(),
                ];

                Log::warning('Commission export failed', [
                    'distributor_id' => $dist->distributor_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => empty($rows)
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
                'total_gross'        => round(array_sum(array_column($rows, 'gross_commission')), 2),
                'total_tds'          => round(array_sum(array_column($rows, 'tds_amount')), 2),
                'total_net'          => round(array_sum(array_column($rows, 'net_commission')), 2),
            ],

            'data'   => $rows,
            'failed' => $failed,
        ]);
    }

    // ============================================================
    // REPORT #2: Weekly Commission Report (with per-distributor TDS)
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

        $usdRate = (float) Cache::remember('usd_to_inr_rate', 3600, function () {
            return \App\Models\Setting::where('group', 'exchange_rate')
                ->where('key', 'usd_to_inr')->value('value') ?: 0;
        });

        if ($usdRate <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Exchange rate not configured. Contact admin.'
            ], 500);
        }

        $query = User::query()
            ->with(['distributorProfile'])
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select('id', 'full_name', 'email', 'distributor_id', 'complete_pan_card')
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

        // Pre-compute TDS info per distributor (based on complete_pan_card)
        $tdsInfoMap = [];
        foreach ($distributors as $dist) {
            $hasPanCard = !empty(trim((string) $dist->complete_pan_card))
                && $dist->complete_pan_card !== '-';

            $tdsInfoMap[$dist->distributor_id] = [
                'tds_rate'    => $hasPanCard ? 0.02 : 0.20,
                'tds_section' => $hasPanCard ? '194-O' : '194J',
            ];
        }

        $perDistributor = [];
        $failed         = [];

        foreach ($distributors as $dist) {
            try {
                $transactions = $this->fetchAllPointTxsSince($dist->distributor_id, $cutoff, $usdRate);
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
            $aggTdsInr   = 0;
            $aggNetInr   = 0;
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

                // Per-distributor TDS (on positive commission only)
                $tdsInfo        = $tdsInfoMap[$distBaId] ?? ['tds_rate' => 0.20, 'tds_section' => '194J'];
                $grossCommissionInr = (float) ($b['gross_commission_inr'] ?? 0);
                $tdsAmountInr   = round($grossCommissionInr * $tdsInfo['tds_rate'], 2);
                $netCommissionInr = round($grossCommissionInr - $tdsAmountInr, 2);

                $aggCashUsd += $cash;
                $aggGreen   += $green;
                $aggYellow  += $yellow;
                $aggBlue    += $blue;
                $aggCvLeft  += $cvL;
                $aggCvRight += $cvR;
                $aggTdsInr  += $tdsAmountInr;
                $aggNetInr  += $netCommissionInr;
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

                    // TDS per distributor
                    'tds_section'      => $tdsInfo['tds_section'],
                    'tds_rate_percent' => round($tdsInfo['tds_rate'] * 100, 2),
                    'gross_commission_inr' => $grossCommissionInr,
                    'tds_amount_inr'   => $tdsAmountInr,
                    'net_commission_inr' => $netCommissionInr,
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

                // TDS summary for the week
                'total_tds_inr'      => round($aggTdsInr, 2),
                'total_net_commission_inr' => round($aggNetInr, 2),

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
    // DECRYPT HELPER (masking removed)
    // ============================================================

    private function decryptField(?string $encrypted): ?string
    {
        if (empty($encrypted)) {
            return null;
        }

        // Layer 1: Serialized string (s:N:"value";) — most likely case
        if (preg_match('/^s:\d+:"(.*)";$/s', $encrypted, $m)) {
            return $m[1];
        }

        // Layer 2: Try Crypt::decryptString (properly encrypted)
        try {
            $decrypted = \Crypt::decryptString($encrypted);

            // Decrypted value might itself be serialized
            if (preg_match('/^s:\d+:"(.*)";$/s', $decrypted, $m)) {
                return $m[1];
            }

            return $decrypted;
        } catch (\Throwable $e) {
            // Layer 3: Plain value (already decrypted / not encrypted)
            return $encrypted;
        }
    }

    // ============================================================
    // CALENDAR HELPERS (use start_at / end_at — exact boundaries)
    // ============================================================

    /**
     * Exact week boundaries from Ignite Sales Calendar (IST).
     * Uses start_at / end_at (UTC+8) → IST.
     */
    // private function getWeekRange(int $week, int $year): array
    // {
    //     $calendar = Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {
    //         $resp = $this->ignite->salesCalendar($year);
    //         return ($resp['code'] ?? null) === ResultCode::OK->value ? $resp['data'] : null;
    //     });

    //     if (!$calendar) {
    //         throw new \Exception("Could not fetch Ignite Sales Calendar for year {$year}");
    //     }

    //     foreach (($calendar['months'] ?? []) as $month) {
    //         foreach (($month['weeks'] ?? []) as $w) {
    //             if ((int) ($w['week_no'] ?? 0) === $week) {
    //                 // Prefer start_at / end_at (exact boundaries); fallback to start_date/end_date
    //                 $startRaw = $w['start_at'] ?? ($w['start_date'] ?? '') . ' 00:00:00';
    //                 $endRaw   = $w['end_at']   ?? null;

    //                 $startIst = Carbon::parse($startRaw, 'Asia/Hong_Kong')
    //                     ->setTimezone('Asia/Kolkata');

    //                 if ($endRaw) {
    //                     $endIst = Carbon::parse($endRaw, 'Asia/Hong_Kong')
    //                         ->setTimezone('Asia/Kolkata')
    //                         ->subSecond();
    //                 } else {
    //                     $endIst = Carbon::parse(($w['end_date'] ?? '') . ' 00:00:00', 'Asia/Hong_Kong')
    //                         ->addDay()
    //                         ->setTimezone('Asia/Kolkata')
    //                         ->subSecond();
    //                 }

    //                 return [$startIst, $endIst];
    //             }
    //         }
    //     }

    //     throw new \Exception("Week {$week} not found in Ignite Calendar for year {$year}");
    // }
    private function getWeekRange(int $week, int $year): array
    {
        $calendar = Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {

            $resp = $this->ignite->salesCalendar($year);

            return ($resp['code'] ?? null) === ResultCode::OK->value
                ? ($resp['data'] ?? null)
                : null;
        });

        if (!$calendar) {
            throw new \Exception("Could not fetch Ignite Sales Calendar for year {$year}");
        }

        foreach ($calendar['months'] ?? [] as $month) {

            foreach ($month['weeks'] ?? [] as $w) {

                if ((int) ($w['week_no'] ?? 0) === $week) {
                    return $this->getIstWeekBoundaries($w);
                }
            }
        }

        throw new \Exception("Week {$week} not found in Ignite Calendar for year {$year}");
    }

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

    // private function buildWeekWindows(array $calendar): array
    // {
    //     $windows = [];

    //     foreach (($calendar['months'] ?? []) as $month) {
    //         foreach (($month['weeks'] ?? []) as $w) {
    //             if (empty($w['start_date']) && empty($w['start_at'])) {
    //                 continue;
    //             }

    //             $startRaw = $w['start_at'] ?? ($w['start_date'] ?? '') . ' 00:00:00';
    //             $endRaw   = $w['end_at']   ?? null;

    //             $startIst = Carbon::parse($startRaw, 'Asia/Hong_Kong')
    //                 ->setTimezone('Asia/Kolkata');

    //             if ($endRaw) {
    //                 $endIst = Carbon::parse($endRaw, 'Asia/Hong_Kong')
    //                     ->setTimezone('Asia/Kolkata')
    //                     ->subSecond();
    //             } elseif (!empty($w['end_date'])) {
    //                 $endIst = Carbon::parse($w['end_date'] . ' 00:00:00', 'Asia/Hong_Kong')
    //                     ->addDay()
    //                     ->setTimezone('Asia/Kolkata')
    //                     ->subSecond();
    //             } else {
    //                 continue;
    //             }

    //             $windows[] = [
    //                 'week_no'    => (int) $w['week_no'],
    //                 'start'      => $startIst,
    //                 'end'        => $endIst,
    //                 'is_current' => (bool) ($w['is_current'] ?? false),
    //                 'is_settled' => (bool) ($w['is_settled'] ?? false),
    //             ];
    //         }
    //     }

    //     usort($windows, fn($a, $b) => $a['week_no'] <=> $b['week_no']);
    //     return $windows;
    // }

    private function buildWeekWindows(array $calendar): array
    {
        $windows = [];

        foreach ($calendar['months'] ?? [] as $month) {

            foreach ($month['weeks'] ?? [] as $w) {

                if (empty($w['start_date']) && empty($w['start_at'])) {
                    continue;
                }

                [$startIst, $endIst] = $this->getIstWeekBoundaries($w);

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
    private function fetchHistoryForWeek(string $baId, Carbon $weekStart, Carbon $weekEnd, float $usdRate): array
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
                    $collected[] = $this->normalizeRow($row, $usdRate);
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
    private function fetchAllPointTxsSince(string $baId, Carbon $cutoff, float $usdRate, int $maxPages = 200): array
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

                $row['_usd_rate'] = $usdRate;
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
    private function normalizeRow(array $row, float $usdRate): array
    {
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
            'tx_type'     => $row['tx_type'] ?? null,
            'description' => $row['description'] ?? '',
            'amount_usd'  => $amount,
            'amount_inr'  => $amountInr,
            'created_ist' => $createdIst,
        ];
    }

    /**
     * Bucket transactions into weeks.
     * Also tracks gross commission (positive cash_bal, excluding withdrawals) per week.
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
                $buckets[$matchedWeek] = [
                    'tx_count'              => 0,
                    'gross_commission_inr'  => 0,
                ];
            }

            $buckets[$matchedWeek]['tx_count']++;

            $point = $tx['point_type'] ?? '';
            $amt   = (float) ($tx['amount'] ?? 0);

            if (in_array($point, ['cash_bal', 'green_bal', 'yellow_bal', 'blue_bal', 'pv1_bal', 'pv2_bal'], true)) {
                $buckets[$matchedWeek][$point] = ($buckets[$matchedWeek][$point] ?? 0) + $amt;
            }

            // Gross commission = positive cash_bal, excluding withdrawals
            if ($point === 'cash_bal') {
                $desc = $tx['description'] ?? '';
                $isWithdrawal = ($desc === 'wd_partner_payout' || str_starts_with($desc, 'wd_'));

                if (!$isWithdrawal && $amt > 0) {
                    $usdRate = (float) ($tx['_usd_rate'] ?? 0);
                    $buckets[$matchedWeek]['gross_commission_inr'] += round($amt * $usdRate, 2);
                }
            }
        }

        return $buckets;
    }

    private function getIstWeekBoundaries(array $week): array
    {
        $sourceTimezone = 'Asia/Hong_Kong';
        $targetTimezone = 'Asia/Kolkata';

        $startRaw = $week['start_at'] ?? null;
        $endRaw   = $week['end_at'] ?? null;

        if (!$startRaw && !empty($week['start_date'])) {
            $startRaw = $week['start_date'] . ' 02:00:00';
        }

        if (!$endRaw && !empty($week['end_date'])) {
            $endRaw = Carbon::parse(
                $week['end_date'],
                $sourceTimezone
            )->addDay()->setTime(2, 0, 0)->format('Y-m-d H:i:s');
        }

        if (!$startRaw || !$endRaw) {
            throw new \Exception('Invalid Ignite calendar week boundaries.');
        }

        $startIst = Carbon::parse($startRaw, $sourceTimezone)
            ->setTimezone($targetTimezone);

        // end_at is exclusive, so subtract one second.
        $endIst = Carbon::parse($endRaw, $sourceTimezone)
            ->setTimezone($targetTimezone)
            ->subSecond();

        return [$startIst, $endIst];
    }

}