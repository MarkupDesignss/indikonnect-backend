<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SalesReportExportController extends Controller
{
    protected IgniteClient $ignite;

    private const IGNITE_TZ = 'Asia/Hong_Kong';
    private const LOCAL_TZ  = 'Asia/Kolkata';

    /**
     * Client-confirmed sales calendar:
     *   Week runs Friday 21:30:00 IST → Friday 21:29:59 IST.
     *
     * Ignite's raw start_at is Saturday 02:00:00 HKST
     *   = Friday 23:30:00 IST.
     *
     * To match the client's calendar, shift every Ignite boundary
     * backwards by 2 hours:
     *   Friday 23:30 IST  →  Friday 21:30 IST
     *   Friday 23:30 IST  →  Friday 21:30 IST   (next week end)
     */
    private const WEEK_SHIFT_HOURS = 2;

    private const SALE_STATUSES = [
        'confirmed',
        'processing',
        'dispatched',
        'shipped',
        'delivered',
        'partial_delivered',
    ];

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    // =================================================================
    // ENDPOINT 0 — Report metadata
    // GET /api/admin/finance/sales-report/meta?year=2026
    // =================================================================
    public function meta(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $year     = (int) ($request->year ?? now()->year);
        $calendar = $this->getCalendar($year);

        $weeks = collect($calendar['months'] ?? [])
            ->flatMap(fn($m) => $m['weeks'] ?? [])
            ->sortBy('week_no')
            ->values();

        if ($weeks->isEmpty()) {
            $prev  = $this->getCalendar($year - 1);
            $weeks = collect($prev['months'] ?? [])
                ->flatMap(fn($m) => $m['weeks'] ?? [])
                ->sortBy('week_no')
                ->values();
        }

        if ($weeks->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => "No sales calendar found for year {$year} or " . ($year - 1),
            ], 503);
        }

        $current = $weeks->firstWhere('is_current', true)
            ?? $weeks->where('is_settled', true)->sortByDesc('week_no')->first()
            ?? $weeks->last();

        $settled = $weeks->where('is_settled', true)->count();

        return response()->json([
            'status' => true,
            'data'   => [
                'year'                 => $year,
                'current_week'         => $current ? (int) $current['week_no'] : null,
                'current_week_settled' => (bool) ($current['is_settled'] ?? false),
                'first_week_no'        => $weeks->first()['week_no'] ?? null,
                'last_week_no'         => $weeks->last()['week_no'] ?? null,
                'total_weeks'          => $weeks->count(),
                'settled_weeks'        => $settled,
                'max_weeks_back'       => $settled > 0 ? $settled : min($weeks->count(), 52),
                'calendar_rule'        => 'Friday 21:30:00 IST → Friday 21:29:59 IST',
                'available_weeks'      => $weeks->map(function ($w) {
                    [$start, $end] = $this->shiftedWeekBounds($w);
                    return [
                        'week_no'    => (int) $w['week_no'],
                        'start_at'   => $start->copy()->setTimezone(self::LOCAL_TZ)->toDateTimeString(),
                        'end_at'     => $end->copy()->setTimezone(self::LOCAL_TZ)->toDateTimeString(),
                        'is_current' => (bool) ($w['is_current'] ?? false),
                        'is_settled' => (bool) ($w['is_settled'] ?? false),
                    ];
                })->values(),
            ],
        ]);
    }

    // =================================================================
    // ENDPOINT 1 — Sales & OS Report
    // =================================================================
    public function categoryReport(Request $request)
    {
        $request->validate([
            'year'       => 'nullable|integer|min:2020|max:2100',
            'week'       => 'nullable|integer|min:0|max:53',
            'weeks_back' => 'nullable|integer|min:1|max:52',
            'include'    => 'nullable|string',
        ]);

        $year      = (int) ($request->year ?? now()->year);
        $week      = (int) ($request->week ?? 0);
        $weeksBack = (int) ($request->weeks_back ?? 23);
        $include   = $request->filled('include')
            ? array_map('trim', explode(',', $request->include))
            : null;
        $want = fn(string $s) => $include === null || in_array($s, $include, true);

        if ($week === 0) {
            $week = $this->currentWeekFromCalendar($year);
            if ($week === null) {
                return response()->json([
                    'status'  => false,
                    'message' => "No week found in Ignite calendar for year {$year}.",
                ], 503);
            }
        }

        $weekRanges = $this->resolveWeekRanges($year, $week, $weeksBack);
        if (empty($weekRanges)) {
            return response()->json([
                'status'  => false,
                'message' => 'No sales weeks found in Ignite calendar for the requested range',
                'debug'   => ['year' => $year, 'week' => $week, 'weeks_back' => $weeksBack],
            ], 503);
        }

        $weekNos = array_keys($weekRanges);

        $salesByCat   = $this->categoryWeekly('sales',   $weekRanges);
        $returnsByCat = $this->categoryWeekly('returns', $weekRanges);
        $grossByWeek  = $this->grossRetailWeekly($weekRanges);
        $cvByWeek     = $this->cvByWeek($year, $weekNos);
        $osByCat      = $this->outstandingByCategory($year, $weekRanges);

        $netByWeek = [];
        foreach ($weekNos as $w) {
            $s = array_sum(array_map(fn($row) => $row[$w] ?? 0, $salesByCat));
            $r = array_sum(array_map(fn($row) => $row[$w] ?? 0, $returnsByCat));
            $netByWeek[$w] = round($s + $r, 2);
        }

        $salesRows   = $this->allCategoryRows($salesByCat,   $weekNos);
        $returnsRows = $this->allCategoryRows($returnsByCat, $weekNos);
        $osRows      = $this->allCategoryRows($osByCat,      $weekNos, 4);

        $salesTotals   = $this->columnTotals($salesRows,   $weekNos);
        $returnsTotals = $this->columnTotals($returnsRows, $weekNos);
        $osTotals      = $this->columnTotals($osRows,      $weekNos, 4);

        // Fill gross_sales_retail with all weeks (consistent shape)
        $grossFilled = [];
        foreach ($weekNos as $w) {
            $grossFilled[$w] = round($grossByWeek[$w] ?? 0, 2);
        }
        $grossFilled['total'] = round(array_sum($grossFilled), 2);

        return response()->json([
            'status'  => true,
            'message' => 'Sales & OS report fetched successfully',
            'data'    => [
                'year'         => $year,
                'anchor_week'  => $week,
                'weeks'        => $weekNos,
                'calendar_rule' => 'Friday 21:30:00 IST → Friday 21:29:59 IST',
                'week_ranges'  => $this->formatWeekRanges($weekRanges),

                'sales_by_category'        => $want('sales')       ? $salesRows     : null,
                'total_sales'              => $want('sales')       ? $salesTotals   : null,

                'sales_return_by_category' => $want('returns')     ? $returnsRows   : null,
                'total_sales_return'       => $want('returns')     ? $returnsTotals : null,

                'net_sales'                => $want('net')         ? $netByWeek + ['total' => round(array_sum($netByWeek), 2)] : null,

                'gross_sales_retail'       => $want('gross')       ? $grossFilled : null,
                'net_sales_ba_price'       => $want('ba_price')    ? $netByWeek + ['total' => round(array_sum($netByWeek), 2)] : null,

                'cv'                       => $want('cv')          ? $cvByWeek + ['total' => round(array_sum($cvByWeek), 4)] : null,
                'cv_over_net'              => $want('cv')          ? $this->ratioWeek($cvByWeek, $netByWeek) : null,

                'outstanding_by_category'  => $want('outstanding') ? $osRows       : null,
                'total_outstanding'        => $want('outstanding') ? $osTotals     : null,
                'os_over_net'              => $want('outstanding') ? $this->ratioWeek($osTotals, $netByWeek) : null,
            ],
        ]);
    }

    // =================================================================
    // ENDPOINT 2 — Product by Product Report
    // =================================================================
    public function productReport(Request $request)
    {
        $request->validate([
            'year'        => 'nullable|integer|min:2020|max:2100',
            'week'        => 'nullable|integer|min:0|max:53',
            'weeks_back'  => 'nullable|integer|min:1|max:26',
            'category_id' => 'nullable|integer|exists:categories,id',
            'product_ids' => 'nullable|string',
        ]);

        $year      = (int) ($request->year ?? now()->year);
        $week      = (int) ($request->week ?? 0);
        $weeksBack = (int) ($request->weeks_back ?? 5);

        if ($week === 0) {
            $week = $this->currentWeekFromCalendar($year);
            if ($week === null) {
                return response()->json([
                    'status'  => false,
                    'message' => "No week found in Ignite calendar for year {$year}.",
                ], 503);
            }
        }

        $weekRanges = $this->resolveWeekRanges($year, $week, $weeksBack);
        if (empty($weekRanges)) {
            return response()->json([
                'status'  => false,
                'message' => 'No sales weeks found in Ignite calendar for the requested range',
            ], 503);
        }

        $weekNos = array_keys($weekRanges);

        $productQuery = Product::select('id', 'name', 'category_id')
            ->whereNull('deleted_at')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $productQuery->where('category_id', (int) $request->category_id);
        }
        if ($request->filled('product_ids')) {
            $ids = array_filter(array_map('intval', explode(',', $request->product_ids)));
            if ($ids) $productQuery->whereIn('id', $ids);
        }
        $products = $productQuery->get();

        $salesByProduct  = $this->productWeekly('sales',  $weekRanges);
        $ordersByProduct = $this->productWeekly('orders', $weekRanges);
        $salesByCat      = $this->categoryWeekly('sales', $weekRanges);

        $categoryRows = $this->allCategoryRows($salesByCat, $weekNos);
        $catTotals    = $this->columnTotals($categoryRows, $weekNos);

        return response()->json([
            'status'  => true,
            'message' => 'Product report fetched successfully',
            'data'    => [
                'year'          => $year,
                'anchor_week'   => $week,
                'weeks'         => $weekNos,
                'calendar_rule' => 'Friday 21:30:00 IST → Friday 21:29:59 IST',

                'category_summary' => [
                    'rows'          => $categoryRows,
                    'weekly_totals' => $catTotals,
                ],

                'product_sales' => $this->productBlock($products, $salesByProduct,  $weekNos, 'money'),
                'order_counts'  => $this->productBlock($products, $ordersByProduct, $weekNos, 'int'),
            ],
        ]);
    }

    // =================================================================
    // WEEK RESOLUTION — with client's 2-hour shift
    // =================================================================

    /**
     * Shift an Ignite week row's raw start_at/end_at by WEEK_SHIFT_HOURS backwards.
     * Returns [Carbon $start, Carbon $end] in Ignite timezone.
     */
    private function shiftedWeekBounds(array $weekRow): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $weekRow['start_at'], self::IGNITE_TZ)
            ->subHours(self::WEEK_SHIFT_HOURS);

        $end = Carbon::createFromFormat('Y-m-d H:i:s', $weekRow['end_at'], self::IGNITE_TZ)
            ->subHours(self::WEEK_SHIFT_HOURS);

        return [$start, $end];
    }

    private function currentWeekFromCalendar(int $year): ?int
    {
        $calendar = $this->getCalendar($year);
        $weeks = collect($calendar['months'] ?? [])
            ->flatMap(fn($m) => $m['weeks'] ?? []);

        if ($weeks->isEmpty()) {
            $prev  = $this->getCalendar($year - 1);
            $weeks = collect($prev['months'] ?? [])
                ->flatMap(fn($m) => $m['weeks'] ?? []);
            if ($weeks->isEmpty()) return null;
        }

        $current = $weeks->firstWhere('is_current', true);
        if ($current) return (int) $current['week_no'];

        $settled = $weeks->where('is_settled', true)->sortByDesc('week_no')->first();
        if ($settled) return (int) $settled['week_no'];

        return (int) $weeks->max('week_no');
    }

    /**
     * Returns [week_no => [Carbon start, Carbon end]] with client's 2h shift applied.
     */
    private function resolveWeekRanges(int $year, int $currentWeek, int $weeksBack): array
    {
        $ranges = [];
        $w = $currentWeek;
        $y = $year;

        for ($i = 0; $i < $weeksBack; $i++) {
            $calendar = $this->getCalendar($y);
            $weeks = collect($calendar['months'] ?? [])
                ->flatMap(fn($m) => $m['weeks'] ?? [])
                ->keyBy('week_no');

            if (!isset($weeks[$w])) {
                $y--;
                $prev = $this->getCalendar($y);
                $prevWeeks = collect($prev['months'] ?? [])
                    ->flatMap(fn($m) => $m['weeks'] ?? [])
                    ->keyBy('week_no');

                if ($prevWeeks->isEmpty()) break;

                $w = (int) $prevWeeks->keys()->max();
                $weeks = $prevWeeks;
                if (!isset($weeks[$w])) break;
            }

            [$start, $end] = $this->shiftedWeekBounds($weeks[$w]);
            $ranges[$w] = [$start, $end];
            $w--;
        }

        return $ranges;
    }

    private function getCalendar(int $year): array
    {
        return Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {
            try {
                $resp = $this->ignite->salesCalendar($year);
                return ($resp['code'] ?? null) === ResultCode::OK->value ? ($resp['data'] ?? []) : [];
            } catch (\Throwable $e) {
                Log::warning('Ignite salesCalendar failed', ['year' => $year, 'error' => $e->getMessage()]);
                return [];
            }
        });
    }

    private function formatWeekRanges(array $weekRanges): array
    {
        $out = [];
        foreach ($weekRanges as $w => [$start, $end]) {
            $out[$w] = [
                'start_at' => $start->copy()->setTimezone(self::LOCAL_TZ)->toDateTimeString(),
                'end_at'   => $end->copy()->setTimezone(self::LOCAL_TZ)->subSecond()->toDateTimeString(),
                'settled'  => $this->isWeekSettled($start->year, $w),
            ];
        }
        return $out;
    }

    private function isWeekSettled(int $year, int $weekNo): bool
    {
        $cal = $this->getCalendar($year);
        foreach ($cal['months'] ?? [] as $m) {
            foreach ($m['weeks'] ?? [] as $wk) {
                if ((int) $wk['week_no'] === $weekNo) {
                    return (bool) ($wk['is_settled'] ?? false);
                }
            }
        }
        return false;
    }

    /**
     * Map an order's confirmed_at/created_at back to its Ignite week_no
     * using the *shifted* week boundaries (client's Friday 21:30 IST rule).
     */
    private function weekNoFor(Carbon $orderedAt): ?int
    {
        $utc8 = $orderedAt->copy()->setTimezone(self::IGNITE_TZ);
        $calendar = $this->getCalendar($utc8->year);

        foreach ($calendar['months'] ?? [] as $month) {
            foreach ($month['weeks'] ?? [] as $w) {
                [$start, $end] = $this->shiftedWeekBounds($w);
                if ($utc8->greaterThanOrEqualTo($start) && $utc8->lessThan($end)) {
                    return (int) $w['week_no'];
                }
            }
        }
        return null;
    }

    // =================================================================
    // BULK QUERIES (unchanged, except weekNoFor now uses shifted bounds)
    // =================================================================
    private function categoryWeekly(string $mode, array $weekRanges): array
    {
        [$start, $end] = $this->window($weekRanges);
        $dateExpr = 'COALESCE(o.confirmed_at, o.created_at)';

        $q = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->whereRaw("{$dateExpr} >= ?", [$start])
            ->whereRaw("{$dateExpr} <  ?", [$end])
            ->whereNull('o.deleted_at');

        if ($mode === 'sales') {
            $q->whereIn('o.status', self::SALE_STATUSES)
                ->select('p.category_id', DB::raw('SUM(ol.line_total) as amt'), DB::raw("{$dateExpr} as order_date"));
        } else {
            $q->where('o.status', 'returned')
                ->select('p.category_id', DB::raw('SUM(-ol.line_total) as amt'), DB::raw("{$dateExpr} as order_date"));
        }

        $rows = $q->groupBy('p.category_id', DB::raw($dateExpr))->get();

        $out = [];
        foreach ($rows as $r) {
            $wk = $this->weekNoFor(Carbon::parse($r->order_date));
            if ($wk === null) continue;
            $out[$r->category_id][$wk] = ($out[$r->category_id][$wk] ?? 0) + (float) $r->amt;
        }
        return $out;
    }

    private function productWeekly(string $mode, array $weekRanges): array
    {
        [$start, $end] = $this->window($weekRanges);
        $dateExpr = 'COALESCE(o.confirmed_at, o.created_at)';

        $q = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->whereRaw("{$dateExpr} >= ?", [$start])
            ->whereRaw("{$dateExpr} <  ?", [$end])
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', self::SALE_STATUSES);

        if ($mode === 'sales') {
            $q->select('ol.product_id', DB::raw('SUM(ol.line_total) as val'), DB::raw("{$dateExpr} as order_date"))
                ->groupBy('ol.product_id', DB::raw($dateExpr));
        } else {
            $q->select('ol.product_id', DB::raw('COUNT(DISTINCT o.id) as val'), DB::raw("{$dateExpr} as order_date"))
                ->groupBy('ol.product_id', DB::raw($dateExpr));
        }

        $rows = $q->get();

        $out = [];
        foreach ($rows as $r) {
            $wk = $this->weekNoFor(Carbon::parse($r->order_date));
            if ($wk === null) continue;
            $out[$r->product_id][$wk] = ($out[$r->product_id][$wk] ?? 0) + (float) $r->val;
        }
        return $out;
    }

    private function grossRetailWeekly(array $weekRanges): array
    {
        [$start, $end] = $this->window($weekRanges);
        $dateExpr = 'COALESCE(o.confirmed_at, o.created_at)';

        $rows = DB::table('orders as o')
            ->whereRaw("{$dateExpr} >= ?", [$start])
            ->whereRaw("{$dateExpr} <  ?", [$end])
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', self::SALE_STATUSES)
            ->select(DB::raw("{$dateExpr} as order_date"), DB::raw('SUM(o.total_payable) as amt'))
            ->groupBy(DB::raw($dateExpr))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $wk = $this->weekNoFor(Carbon::parse($r->order_date));
            if ($wk === null) continue;
            $out[$wk] = ($out[$wk] ?? 0) + (float) $r->amt;
        }
        return $out;
    }

    private function cvByWeek(int $year, array $weekNos): array
    {
        $out = [];
        foreach ($weekNos as $w) {
            $out[$w] = Cache::remember("ignite_cv_{$year}_{$w}", 3600, function () use ($year, $w) {
                try {
                    $resp = $this->ignite->pairings($year, $w);
                    if (($resp['code'] ?? null) !== ResultCode::OK->value) return 0.0;
                    return collect($resp['data']['rows'] ?? [])
                        ->sum(fn($r) => (float) ($r['paired_cv'] ?? 0));
                } catch (\Throwable $e) {
                    Log::warning('Ignite pairings failed', ['year' => $year, 'week' => $w, 'error' => $e->getMessage()]);
                    return 0.0;
                }
            });
        }
        return $out;
    }

    private function outstandingByCategory(int $year, array $weekRanges): array
    {
        if (!Schema::hasTable('ignite_outstanding_shipments')) {
            return [];
        }

        [$start, $end] = $this->window($weekRanges);

        $rows = DB::table('ignite_outstanding_shipments as os')
            ->join('products as p', 'p.id', '=', 'os.product_id')
            ->where('os.sales_year', $year)
            ->whereBetween('os.sales_week_no', [min(array_keys($weekRanges)), max(array_keys($weekRanges))])
            ->where('os.created_at', '>=', $start)
            ->where('os.created_at', '<',  $end)
            ->select('p.category_id', 'os.sales_week_no', DB::raw('SUM(os.amount) as amt'))
            ->groupBy('p.category_id', 'os.sales_week_no')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->category_id][$r->sales_week_no] =
                ($out[$r->category_id][$r->sales_week_no] ?? 0) + (float) $r->amt;
        }
        return $out;
    }

    // =================================================================
    // BUILDERS
    // =================================================================
    private function allCategoryRows(array $byCat, array $weekNos, int $precision = 2): array
    {
        $cats = Category::select('id', 'title')->orderBy('title')->get();
        $rows = [];
        foreach ($cats as $cat) {
            $weekly = [];
            foreach ($weekNos as $w) {
                $weekly[$w] = round($byCat[$cat->id][$w] ?? 0, $precision);
            }
            $rows[] = [
                'category_id' => $cat->id,
                'category'    => $cat->title,
                'weekly'      => $weekly,
                'total'       => round(array_sum($weekly), $precision),
            ];
        }
        return $rows;
    }

    private function columnTotals(array $rows, array $weekNos, int $precision = 2): array
    {
        $totals = array_fill_keys($weekNos, 0);
        foreach ($rows as $row) {
            foreach ($weekNos as $w) {
                $totals[$w] += $row['weekly'][$w] ?? 0;
            }
        }
        return array_map(fn($v) => round($v, $precision), $totals)
            + ['total' => round(array_sum($totals), $precision)];
    }

    private function productBlock($products, array $weeklyData, array $weekNos, string $cast): array
    {
        $rows = [];
        $totals = array_fill_keys($weekNos, 0);

        foreach ($products as $p) {
            $row = [];
            foreach ($weekNos as $w) {
                $v = $weeklyData[$p->id][$w] ?? 0;
                $row[$w] = $cast === 'money' ? round((float) $v, 2) : (int) $v;
                $totals[$w] += $row[$w];
            }
            $rows[] = [
                'product_id'   => $p->id,
                'product_name' => $p->name,
                'weekly'       => $row,
                'total'        => $cast === 'money' ? round(array_sum($row), 2) : array_sum($row),
            ];
        }

        return [
            'rows'          => $rows,
            'weekly_totals' => $cast === 'money'
                ? array_map(fn($v) => round($v, 2), $totals) + ['total' => round(array_sum($totals), 2)]
                : $totals + ['total' => array_sum($totals)],
        ];
    }

    private function ratioWeek(array $numerator, array $denominator): array
    {
        $out = [];
        foreach ($denominator as $w => $den) {
            if ($w === 'total') continue;
            $out[$w] = ($den ?? 0) != 0
                ? round(($numerator[$w] ?? 0) / $den, 4)
                : null;
        }
        $numTotal = $numerator['total'] ?? array_sum(array_diff_key($numerator, ['total' => 1]));
        $denTotal = $denominator['total'] ?? array_sum(array_diff_key($denominator, ['total' => 1]));
        $out['total'] = $denTotal != 0 ? round($numTotal / $denTotal, 4) : null;
        return $out;
    }

    private function window(array $weekRanges): array
    {
        $starts = array_map(fn($r) => $r[0], $weekRanges);
        $ends   = array_map(fn($r) => $r[1], $weekRanges);
        return [min($starts), max($ends)];
    }
}
