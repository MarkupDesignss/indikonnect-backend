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
            'week'      => 'nullable|integer|min:0|max:53',
            'year'      => 'nullable|integer|min:2020|max:2100',
            'from_date' => 'nullable|date_format:Y-m-d|required_with:to_date',
            'to_date'   => 'nullable|date_format:Y-m-d|required_with:from_date|after_or_equal:from_date',
        ]);

        $hasWeek  = $request->filled('week') && $request->filled('year');
        $hasRange = $request->filled('from_date') && $request->filled('to_date');

        if (!$hasWeek && !$hasRange) {
            return response()->json([
                'success' => false,
                'message' => 'Provide week+year and/or from_date+to_date.',
            ], 422);
        }

        $week = $hasWeek ? (int) $request->week : null;
        $year = $hasWeek ? (int) $request->year : null;

        // ---------- DEBUG MODE (week=0) ----------
        if ($hasWeek && $week === 0) {
            $response = $this->ignite->salesCalendar($year);
            $calendar = $response['data'] ?? [];

            foreach ($calendar['months'] as &$month) {
                foreach ($month['weeks'] as &$w) {
                    $startRaw = $w['start_at'] ?? null;
                    $endRaw   = $w['end_at'] ?? null;

                    if ($startRaw) {
                        $startIst = Carbon::parse($startRaw, 'Asia/Hong_Kong')
                            ->setTimezone('Asia/Kolkata');
                        $w['start_at']   = $startIst->format('Y-m-d H:i:s');
                        $w['start_date'] = $startIst->format('Y-m-d');
                    }

                    if ($endRaw) {
                        $endIst = Carbon::parse($endRaw, 'Asia/Hong_Kong')
                            ->setTimezone('Asia/Kolkata')
                            ->subSecond();
                        $w['end_at']   = $endIst->format('Y-m-d H:i:s');
                        $w['end_date'] = $endIst->format('Y-m-d');
                    }
                }
                unset($w);

                if (!empty($month['weeks'])) {
                    $firstWeek = reset($month['weeks']);
                    $lastWeek  = end($month['weeks']);
                    $month['start_date'] = $firstWeek['start_date'] ?? null;
                    $month['end_date']   = $lastWeek['end_date'] ?? null;
                }
            }
            unset($month);

            return response()->json([
                'success'  => true,
                'message'  => 'Full Ignite Sales Calendar (IST) for ' . $year,
                'calendar' => $calendar,
            ]);
        }

        // ---------- Build period(s) ----------
        $periods = [];

        if ($hasWeek) {
            [$wStart, $wEnd] = $this->getWeekRange($week, $year);
            $periods[] = [
                'type'  => 'week',
                'label' => "W{$week}-{$year}",
                'start' => $wStart,
                'end'   => $wEnd,
            ];
        }

        if ($hasRange) {
            $rStart = Carbon::parse($request->from_date, 'Asia/Kolkata')->startOfDay();
            $rEnd   = Carbon::parse($request->to_date,   'Asia/Kolkata')->endOfDay();

            if ($rStart->diffInDays($rEnd) > 366) {
                return response()->json([
                    'success' => false,
                    'message' => 'Date range cannot exceed 366 days.',
                ], 422);
            }

            $periods[] = [
                'type'  => 'custom_range',
                'label' => $rStart->toDateString() . ' to ' . $rEnd->toDateString(),
                'start' => $rStart,
                'end'   => $rEnd,
            ];
        }

        // Union window
        $unionStart = collect($periods)->min(fn($p) => $p['start']);
        $unionEnd   = collect($periods)->max(fn($p) => $p['end']);

        Log::info("Commission export started", [
            'periods'     => array_map(fn($p) => [
                'type'  => $p['type'],
                'label' => $p['label'],
                'start' => $p['start']->toDateTimeString(),
                'end'   => $p['end']->toDateTimeString(),
            ], $periods),
            'union_start' => $unionStart->toDateTimeString(),
            'union_end'   => $unionEnd->toDateTimeString(),
        ]);

        $usdRate = (float) Cache::remember('usd_to_inr_rate', 3600, function () {
            return \App\Models\Setting::where('group', 'exchange_rate')
                ->where('key', 'usd_to_inr')->value('value') ?: 0;
        });

        if ($usdRate <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Exchange rate not configured. Contact admin.',
            ], 500);
        }

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
                $history = $this->fetchHistoryInRange(
                    $dist->distributor_id,
                    $unionStart->copy(),
                    $unionEnd->copy(),
                    $usdRate
                );

                // Per-period gross
                $perPeriodGross = [];
                foreach ($periods as $idx => $p) {
                    $gross = 0;
                    foreach ($history as $tx) {
                        if (($tx['point_type'] ?? '') !== 'cash_bal') continue;

                        $desc   = $tx['description'] ?? '';
                        $amount = (float) $tx['amount_inr'];

                        if ($desc === 'wd_partner_payout' || str_starts_with($desc, 'wd_')) {
                            continue;
                        }
                        if ($amount <= 0) continue;

                        $txAt = Carbon::parse($tx['created_ist'], 'Asia/Kolkata');
                        if ($txAt->gte($p['start']) && $txAt->lte($p['end'])) {
                            $gross += $amount;
                        }
                    }
                    $perPeriodGross[$idx] = round($gross, 2);
                }

                // Combined gross
                $combinedGross = 0;
                $seen = [];
                foreach ($history as $tx) {
                    if (($tx['point_type'] ?? '') !== 'cash_bal') continue;

                    $desc   = $tx['description'] ?? '';
                    $amount = (float) $tx['amount_inr'];

                    if ($desc === 'wd_partner_payout' || str_starts_with($desc, 'wd_')) continue;
                    if ($amount <= 0) continue;

                    $txAt = Carbon::parse($tx['created_ist'], 'Asia/Kolkata');
                    $inAny = false;
                    foreach ($periods as $p) {
                        if ($txAt->gte($p['start']) && $txAt->lte($p['end'])) {
                            $inAny = true;
                            break;
                        }
                    }
                    if (!$inAny) continue;
                    if (isset($seen[$tx['id']])) continue;
                    $seen[$tx['id']] = true;

                    $combinedGross += $amount;
                }
                $combinedGross = round($combinedGross, 2);

                if ($combinedGross <= 0) {
                    continue;
                }

                // ---------- TDS ----------
                $profile     = $dist->distributorProfile;
                $gstIn       = $profile?->gst_in;
                $panVerified = (bool) ($profile?->pan_verified ?? false);

                $hasPanCard = !empty(trim((string) $dist->complete_pan_card))
                    && $dist->complete_pan_card !== '-';

                $tdsRate    = $hasPanCard ? 0.02 : 0.20;
                $tdsSection = $hasPanCard ? '194-O' : '194J';

                $tdsAmount     = round($combinedGross * $tdsRate, 2);
                $netCommission = round($combinedGross - $tdsAmount, 2);

                $breakdown = [];
                foreach ($periods as $idx => $p) {
                    $g = $perPeriodGross[$idx] ?? 0;
                    $breakdown[] = [
                        'type'          => $p['type'],
                        'label'         => $p['label'],
                        'start'         => $p['start']->format('Y-m-d H:i:s'),
                        'end'           => $p['end']->format('Y-m-d H:i:s'),
                        'gross_commission' => $g,
                        'tds_amount'    => round($g * $tdsRate, 2),
                        'net_commission'=> round($g - round($g * $tdsRate, 2), 2),
                    ];
                }

                $rows[] = [
                    //'ba_id'            => $dist->distributor_id,
                    'ba_id' => $this->stripBaIdSuffix($dist->distributor_id),
                    'distributor_name' => $dist->full_name,
                    'email'            => $dist->email,

                    'period_type' => count($periods) > 1 ? 'combined' : $periods[0]['type'],
                    'breakdown'   => $breakdown,

                    'gross_commission' => $combinedGross,
                    'tds_section'      => $tdsSection,
                    'tds_rate_percent' => round($tdsRate * 100, 2),
                    'tds_amount'       => $tdsAmount,
                    'net_commission'   => $netCommission,

                    'gst_in'       => $gstIn ?: '-',
                    'pan_verified' => $panVerified,

                    'bank_holder_name'    => $profile->bank_holder_name ?? '-',
                    'bank_ifsc'           => $profile->bank_ifsc ?? '-',
                    'pan_number'          => $dist->complete_pan_card ?? '-',
                    'bank_account_number' => $profile?->complete_bank_account ?? '-',
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

        // ---------- purchase_not (placeholder entry, period meta only) ----------
        $purchaseNot = [[
            'ba_id'            => null,
            'distributor_name' => null,
            'email'            => null,
            'period_type'      => count($periods) > 1 ? 'combined' : $periods[0]['type'],
            'breakdown'        => array_map(fn($p) => [
                'type'             => $p['type'],
                'label'            => $p['label'],
                'start'            => $p['start']->format('Y-m-d H:i:s'),
                'end'              => $p['end']->format('Y-m-d H:i:s'),
                'gross_commission' => null,
                'tds_amount'       => null,
                'net_commission'   => null,
            ], $periods),
            'gross_commission'    => null,
            'tds_section'         => null,
            'tds_rate_percent'    => null,
            'tds_amount'          => null,
            'net_commission'      => null,
            'gst_in'              => null,
            'pan_verified'        => null,
            'bank_holder_name'    => null,
            'bank_ifsc'           => null,
            'pan_number'          => null,
            'bank_account_number' => null,
        ]];

        return response()->json([
            'success'  => true,
            'message'  => empty($rows)
                ? 'No commission data available for the specified period.'
                : 'Commission data fetched successfully.',
            'currency' => [
                'base'          => 'USD',
                'display'       => 'INR',
                'exchange_rate' => $usdRate,
            ],
            'period_type' => count($periods) > 1 ? 'combined' : $periods[0]['type'],
            'filters' => [
                'week'      => $week,
                'year'      => $year,
                'from_date' => $hasRange ? $request->from_date : null,
                'to_date'   => $hasRange ? $request->to_date   : null,
            ],
            'periods' => array_map(fn($p) => [
                'type'  => $p['type'],
                'label' => $p['label'],
                'start' => $p['start']->format('Y-m-d H:i:s'),
                'end'   => $p['end']->format('Y-m-d H:i:s'),
            ], $periods),
            'week_range' => [
                'start' => $unionStart->format('Y-m-d H:i:s'),
                'end'   => $unionEnd->format('Y-m-d H:i:s'),
            ],
            'summary' => [
                'total_distributors' => $distributors->count(),
                'total_eligible'     => count($rows),
                'failed_count'       => count($failed),
                'total_gross'        => round(array_sum(array_column($rows, 'gross_commission')), 2),
                'total_tds'          => round(array_sum(array_column($rows, 'tds_amount')), 2),
                'total_net'          => round(array_sum(array_column($rows, 'net_commission')), 2),
            ],
            'data'         => $rows,
            'purchase_not' => $purchaseNot,
            'failed'       => $failed,
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
            'year'      => 'nullable|integer|min:2020|max:2100',
            'weeks'     => 'nullable|integer|min:1|max:53',
            'ba_id'     => 'nullable|string|max:100',
            'week'      => 'nullable|integer|min:1|max:53',
            'from_date' => 'nullable|date_format:Y-m-d|required_with:to_date',
            'to_date'   => 'nullable|date_format:Y-m-d|required_with:from_date|after_or_equal:from_date',
        ]);

        $hasWeek  = $request->filled('week') && $request->filled('year');
        $hasRange = $request->filled('from_date') && $request->filled('to_date');
        $hasYear  = $request->filled('year');

        if (!$hasWeek && !$hasRange && !$hasYear) {
            return response()->json([
                'success' => false,
                'message' => 'Provide year (last N weeks), or week+year, or from_date+to_date.',
            ], 422);
        }

        $baId = $request->input('ba_id');
        $weeks = null;

        // ---------- Build week windows ----------
        $weekWindows = [];

        if ($hasYear) {
            $year = (int) $request->year;
            $calendar = $this->getRawCalendar($year);
            if (!$calendar) {
                return response()->json([
                    'success' => false,
                    'message' => "Sales calendar for {$year} not available",
                ], 422);
            }

            $allWindows = $this->buildWeekWindows($calendar);

            if ($hasWeek) {
                $one = null;
                foreach ($allWindows as $w) {
                    if ($w['week_no'] === (int) $request->week) {
                        $one = $w;
                        break;
                    }
                }
                if (!$one) {
                    return response()->json([
                        'success' => false,
                        'message' => "Week {$request->week} not in calendar {$year}",
                    ], 422);
                }
                $weekWindows[] = $one;
            } else {
                // last N weeks
                $weeks = min(max((int) $request->input('weeks', 12), 1), 53);
                $weekWindows = array_slice($allWindows, -$weeks);
            }
        }

        if ($hasRange) {
            $rStart = Carbon::parse($request->from_date, 'Asia/Kolkata')->startOfDay();
            $rEnd   = Carbon::parse($request->to_date,   'Asia/Kolkata')->endOfDay();

            if ($rStart->diffInDays($rEnd) > 366) {
                return response()->json([
                    'success' => false,
                    'message' => 'Date range cannot exceed 366 days.',
                ], 422);
            }

            // Multi-year: fetch calendar for both years
            $years = array_unique([$rStart->year, $rEnd->year]);
            $rangeWindows = [];
            foreach ($years as $y) {
                $cal = $this->getRawCalendar((int) $y);
                if (!$cal) continue;
                foreach ($this->buildWeekWindows($cal) as $w) {
                    if ($w['end']->gte($rStart) && $w['start']->lte($rEnd)) {
                        // clamp
                        if ($w['start']->lt($rStart)) $w['start'] = $rStart->copy();
                        if ($w['end']->gt($rEnd))     $w['end']   = $rEnd->copy();
                        $w['_year'] = (int) $y;
                        $w['is_current'] = false;
                        $w['is_settled'] = $w['end']->lt(now('Asia/Kolkata'));
                        $rangeWindows[] = $w;
                    }
                }
            }

            // Merge with week windows (dedup by year+week_no)
            $existing = [];
            foreach ($weekWindows as $w) {
                $existing[($w['_year'] ?? ($hasYear ? $year : 0)) . ':' . $w['week_no']] = true;
            }
            foreach ($rangeWindows as $w) {
                $key = $w['_year'] . ':' . $w['week_no'];
                if (isset($existing[$key])) continue;
                $existing[$key] = true;
                $weekWindows[] = $w;
            }

            // sort by start
            usort($weekWindows, fn($a, $b) => $a['start'] <=> $b['start']);
        }

        if (empty($weekWindows)) {
            return response()->json([
                'success' => false,
                'message' => 'No weeks/range resolved.',
            ], 422);
        }

        $usdRate = (float) Cache::remember('usd_to_inr_rate', 3600, function () {
            return \App\Models\Setting::where('group', 'exchange_rate')
                ->where('key', 'usd_to_inr')->value('value') ?: 0;
        });

        if ($usdRate <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Exchange rate not configured. Contact admin.',
            ], 500);
        }

        $query = User::query()
            ->with(['distributorProfile'])
            ->where('account_type', 'distributor')
            ->whereNotNull('distributor_id')
            ->where('distributor_id', '!=', '')
            ->select('id', 'full_name', 'email', 'distributor_id', 'complete_pan_card')
            ->orderBy('id');

        if ($baId) $query->where('distributor_id', $baId);

        $distributors = $query->get();
        if ($distributors->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No distributors found'], 404);
        }

        $cutoff = $weekWindows[0]['start'];

        // TDS info map
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
        $failed = [];

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

        // Latest first
        foreach (array_reverse($weekWindows) as $win) {
            $weekNo = $win['week_no'];

            $aggCashUsd = 0; $aggGreen = 0; $aggYellow = 0; $aggBlue = 0;
            $aggCvLeft = 0; $aggCvRight = 0; $aggTdsInr = 0; $aggNetInr = 0;
            $txCount = 0; $distributorsInWeek = [];

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

                $tdsInfo            = $tdsInfoMap[$distBaId] ?? ['tds_rate' => 0.20, 'tds_section' => '194J'];
                $grossCommissionInr = (float) ($b['gross_commission_inr'] ?? 0);
                $tdsAmountInr       = round($grossCommissionInr * $tdsInfo['tds_rate'], 2);
                $netCommissionInr   = round($grossCommissionInr - $tdsAmountInr, 2);

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
                'week_no'    => $weekNo,
                'year'       => $win['_year'] ?? $year ?? null,
                'start'      => $win['start']->format('Y-m-d H:i:s'),
                'end'        => $win['end']->format('Y-m-d H:i:s'),
                'is_current' => $win['is_current'],
                'is_settled' => $win['is_settled'],
                'total_cash_usd'     => round($aggCashUsd, 4),
                'total_cash_inr'     => round($aggCashUsd * $usdRate, 2),
                'total_green_coin'   => round($aggGreen, 4),
                'total_yellow_coin'  => round($aggYellow, 4),
                'total_blue_coin'    => round($aggBlue, 4),
                'total_cv_left'      => round($aggCvLeft, 4),
                'total_cv_right'     => round($aggCvRight, 4),
                'total_tx_count'     => $txCount,
                'distributor_count'  => count($distributorsInWeek),
                'total_tds_inr'      => round($aggTdsInr, 2),
                'total_net_commission_inr' => round($aggNetInr, 2),
                'distributors'       => $distributorsInWeek,
            ];

            if ($weeks !== null && count($weekRows) >= $weeks) break;
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
                'year'      => $year ?? null,
                'weeks'     => $weeks,
                'week'      => $hasWeek ? (int) $request->week : null,
                'from_date' => $hasRange ? $request->from_date : null,
                'to_date'   => $hasRange ? $request->to_date   : null,
                'ba_id'     => $baId,
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
                    // Prefer start_at / end_at (exact boundaries); fallback to start_date/end_date
                    $startRaw = $w['start_at'] ?? ($w['start_date'] ?? '') . ' 00:00:00';
                    $endRaw   = $w['end_at']   ?? null;

                    $startIst = Carbon::parse($startRaw, 'Asia/Hong_Kong')
                        ->setTimezone('Asia/Kolkata');

                    if ($endRaw) {
                        $endIst = Carbon::parse($endRaw, 'Asia/Hong_Kong')
                            ->setTimezone('Asia/Kolkata')
                            ->subSecond();
                    } else {
                        $endIst = Carbon::parse(($w['end_date'] ?? '') . ' 00:00:00', 'Asia/Hong_Kong')
                            ->addDay()
                            ->setTimezone('Asia/Kolkata')
                            ->subSecond();
                    }

                    return [$startIst, $endIst];
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

    private function buildWeekWindows(array $calendar): array
    {
        $windows = [];

        foreach (($calendar['months'] ?? []) as $month) {
            foreach (($month['weeks'] ?? []) as $w) {
                if (empty($w['start_date']) && empty($w['start_at'])) {
                    continue;
                }

                $startRaw = $w['start_at'] ?? ($w['start_date'] ?? '') . ' 00:00:00';
                $endRaw   = $w['end_at']   ?? null;

                $startIst = Carbon::parse($startRaw, 'Asia/Hong_Kong')
                    ->setTimezone('Asia/Kolkata');

                if ($endRaw) {
                    $endIst = Carbon::parse($endRaw, 'Asia/Hong_Kong')
                        ->setTimezone('Asia/Kolkata')
                        ->subSecond();
                } elseif (!empty($w['end_date'])) {
                    $endIst = Carbon::parse($w['end_date'] . ' 00:00:00', 'Asia/Hong_Kong')
                        ->addDay()
                        ->setTimezone('Asia/Kolkata')
                        ->subSecond();
                } else {
                    continue;
                }

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

    /**
     * Fetch all pointTxs within an arbitrary range (IST boundaries).
     * Wrapper around fetchHistoryForWeek() — same logic, generic name.
     */
    private function fetchHistoryInRange(string $baId, Carbon $start, Carbon $end, float $usdRate): array
    {
        return $this->fetchHistoryForWeek($baId, $start, $end, $usdRate);
    }

    /**
     * Strip trailing numeric suffix from BA id.
     * e.g. "AIA1675197-001" => "AIA1675197"
     *      "AIA-1675197-002" => "AIA-1675197"
     *      "AIA1675197"      => "AIA1675197"   (unchanged)
     *      "AIA1675197-XYZ"  => "AIA1675197-XYZ" (unchanged, not numeric)
     */
    private function stripBaIdSuffix(?string $baId): ?string
    {
        if ($baId === null || $baId === '') {
            return $baId;
        }

        if (preg_match('/^(.*)-(\d+)$/', $baId, $m)) {
            return $m[1];
        }

        return $baId;
    }
}