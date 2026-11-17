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

class SalesReportExportController extends Controller
{
    protected IgniteClient $ignite;

    /** Ignite sends every timestamp in UTC+8. */
    private const IGNITE_TZ = 'Asia/Hong_Kong';
    /** Business timezone used for date display in the response. */
    private const LOCAL_TZ = 'Asia/Kolkata';

    /** Order statuses that count as a sale. */
    private const SALE_STATUSES = [
        'confirmed', 'dispatched', 'shipped', 'delivered', 'partial_delivered',
    ];

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    // =================================================================
    // ENDPOINT 1 — Sales & OS Report (Category matrix)
    // GET /api/finance/sales-report/category?year=2026&week=38&weeks_back=23
    // =================================================================
    public function categoryReport(Request $request)
    {
        $request->validate([
            'year'       => 'required|integer|min:2020|max:2100',
            'week'       => 'required|integer|min:1|max:53',
            'weeks_back' => 'nullable|integer|min:1|max:52',
            'include'    => 'nullable|string',
        ]);

        $year      = (int) $request->year;
        $week      = (int) $request->week;
        $weeksBack = (int) ($request->weeks_back ?? 23);

        $weekRanges = $this->resolveWeekRanges($year, $week, $weeksBack);
        if (empty($weekRanges)) {
            return response()->json([
                'status'  => false,
                'message' => 'No sales weeks found in Ignite calendar for the requested range',
            ], 503);
        }

        $weekNos = array_keys($weekRanges); // newest → oldest

        // Pre-fetch all four data sets in bulk
        $salesByCat   = $this->categoryWeekly('sales',     $weekRanges);
        $returnsByCat = $this->categoryWeekly('returns',   $weekRanges);
        $grossByWeek  = $this->grossRetailWeekly($weekRanges);
        $cvByWeek     = $this->cvByWeek($year, $weekNos);
        $osByCat      = $this->outstandingByCategory($year, $weekRanges);
        $osByWeek     = $this->sumByWeek($osByCat, $weekNos);

        // Net sales = sales + returns (returns already negative)
        $netByWeek = [];
        foreach ($weekNos as $w) {
            $sales   = array_sum(array_map(fn ($row) => $row[$w] ?? 0, $salesByCat));
            $returns = array_sum(array_map(fn ($row) => $row[$w] ?? 0, $returnsByCat));
            $netByWeek[$w] = round($sales + $returns, 2);
        }

        // Sales-by-category block (rows 4–7 of the sheet)
        $salesRows = [];
        $salesTotals = array_fill_keys($weekNos, 0);
        foreach ($salesByCat as $catId => $weekly) {
            $row = [];
            foreach ($weekNos as $w) {
                $row[$w] = round($weekly[$w] ?? 0, 2);
                $salesTotals[$w] += $row[$w];
            }
            $salesRows[] = [
                'category_id' => $catId,
                'category'    => $this->categoryName($catId),
                'weekly'      => $row,
                'total'       => round(array_sum($row), 2),
            ];
        }
        $totalSales = array_map(fn ($v) => round($v, 2), $salesTotals) + ['total' => round(array_sum($salesTotals), 2)];

        // Returns-by-category block (rows 9–12)
        $returnsRows = [];
        $returnsTotals = array_fill_keys($weekNos, 0);
        foreach ($returnsByCat as $catId => $weekly) {
            $row = [];
            foreach ($weekNos as $w) {
                $row[$w] = round($weekly[$w] ?? 0, 2);
                $returnsTotals[$w] += $row[$w];
            }
            $returnsRows[] = [
                'category_id' => $catId,
                'category'    => $this->categoryName($catId),
                'weekly'      => $row,
                'total'       => round(array_sum($row), 2),
            ];
        }
        $totalReturns = array_map(fn ($v) => round($v, 2), $returnsTotals) + ['total' => round(array_sum($returnsTotals), 2)];

        // Outstanding-by-category block (rows 25–28)
        $osRows = [];
        $osTotals = array_fill_keys($weekNos, 0);
        foreach ($osByCat as $catId => $weekly) {
            $row = [];
            foreach ($weekNos as $w) {
                $row[$w] = round($weekly[$w] ?? 0, 4);
                $osTotals[$w] += $row[$w];
            }
            $osRows[] = [
                'category_id' => $catId,
                'category'    => $this->categoryName($catId),
                'weekly'      => $row,
                'total'       => round(array_sum($row), 4),
            ];
        }
        $totalOs = array_map(fn ($v) => round($v, 4), $osTotals) + ['total' => round(array_sum($osTotals), 4)];

        return response()->json([
            'status'  => true,
            'message' => 'Sales & OS report fetched successfully',
            'data'    => [
                'year'        => $year,
                'anchor_week' => $week,
                'weeks'       => $weekNos,
                'week_ranges' => $this->formatWeekRanges($weekRanges),

                // Sales block
                'sales_by_category' => $salesRows,
                'total_sales'       => $totalSales,

                // Returns block
                'sales_return_by_category' => $returnsRows,
                'total_sales_return'       => $totalReturns,

                // Net sales
                'net_sales' => $netByWeek + ['total' => round(array_sum($netByWeek), 2)],

                // Gross / BA price
                'gross_sales_retail' => $grossByWeek + ['total' => round(array_sum($grossByWeek), 2)],
                'net_sales_ba_price' => $netByWeek + ['total' => round(array_sum($netByWeek), 2)],

                // CV block
                'cv'          => $cvByWeek + ['total' => round(array_sum($cvByWeek), 4)],
                'cv_over_net' => $this->ratioWeek($cvByWeek, $netByWeek),

                // Outstanding block
                'outstanding_by_category' => $osRows,
                'total_outstanding'       => $totalOs,
                'os_over_net'             => $this->ratioWeek($totalOs, $netByWeek),
            ],
        ]);
    }

    // =================================================================
    // ENDPOINT 2 — Product by Product Report
    // GET /api/finance/sales-report/products?year=2026&week=40&weeks_back=5
    // =================================================================
    public function productReport(Request $request)
    {
        $request->validate([
            'year'        => 'required|integer|min:2020|max:2100',
            'week'        => 'required|integer|min:1|max:53',
            'weeks_back'  => 'nullable|integer|min:1|max:26',
            'category_id' => 'nullable|integer|exists:categories,id',
            'product_ids' => 'nullable|string',
        ]);

        $year      = (int) $request->year;
        $week      = (int) $request->week;
        $weeksBack = (int) ($request->weeks_back ?? 5);

        $weekRanges = $this->resolveWeekRanges($year, $week, $weeksBack);
        if (empty($weekRanges)) {
            return response()->json([
                'status'  => false,
                'message' => 'No sales weeks found in Ignite calendar for the requested range',
            ], 503);
        }

        $weekNos = array_keys($weekRanges);

        // Product list (filtered)
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

        // Bulk weekly data
        $salesByProduct  = $this->productWeekly('sales',  $weekRanges);
        $ordersByProduct = $this->productWeekly('orders', $weekRanges);
        $salesByCat      = $this->categoryWeekly('sales', $weekRanges);

        // Category summary block (rows 7–9 of sheet 2)
        $categoryRows = [];
        $catTotals = array_fill_keys($weekNos, 0);
        foreach ($salesByCat as $catId => $weekly) {
            $row = [];
            foreach ($weekNos as $w) {
                $row[$w] = round($weekly[$w] ?? 0, 2);
                $catTotals[$w] += $row[$w];
            }
            $categoryRows[] = [
                'category_id' => $catId,
                'category'    => $this->categoryName($catId),
                'weekly'      => $row,
                'total'       => round(array_sum($row), 2),
            ];
        }

        return response()->json([
            'status'  => true,
            'message' => 'Product report fetched successfully',
            'data'    => [
                'year'        => $year,
                'anchor_week' => $week,
                'weeks'       => $weekNos,

                'category_summary' => [
                    'rows'          => $categoryRows,
                    'weekly_totals' => array_map(fn ($v) => round($v, 2), $catTotals)
                        + ['total' => round(array_sum($catTotals), 2)],
                ],

                'product_sales' => $this->productBlock($products, $salesByProduct, $weekNos, 'money'),

                'order_counts'  => $this->productBlock($products, $ordersByProduct, $weekNos, 'int'),
            ],
        ]);
    }

    // =================================================================
    // WEEK RANGE RESOLUTION (Ignite calendar, Saturday 02:00 UTC+8)
    // =================================================================
    /**
     * Returns [week_no => [Carbon $start, Carbon $end]]
     * Start inclusive, end EXCLUSIVE (matches Ignite ledger).
     */
    private function resolveWeekRanges(int $year, int $currentWeek, int $weeksBack): array
    {
        $ranges = [];
        $w = $currentWeek;
        $y = $year;

        for ($i = 0; $i < $weeksBack; $i++) {
            $calendar = $this->getCalendar($y);
            $weeks = collect($calendar['months'] ?? [])
                ->flatMap(fn ($m) => $m['weeks'] ?? [])
                ->keyBy('week_no');

            if (!isset($weeks[$w])) {
                // Rollover into the previous sales year
                $y--;
                $prev = $this->getCalendar($y);
                $prevWeeks = collect($prev['months'] ?? [])
                    ->flatMap(fn ($m) => $m['weeks'] ?? [])
                    ->keyBy('week_no');

                if ($prevWeeks->isEmpty()) {
                    break;
                }

                // Map the requested week into the previous year's numbering
                $maxPrev = (int) $prevWeeks->keys()->max();
                $w = $maxPrev;
                $weeks = $prevWeeks;
                if (!isset($weeks[$w])) break;
            }

            $wk = $weeks[$w];
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $wk['start_at'], self::IGNITE_TZ);
            $end   = Carbon::createFromFormat('Y-m-d H:i:s', $wk['end_at'],   self::IGNITE_TZ);

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
                'end_at'   => $end->copy()->setTimezone(self::LOCAL_TZ)->toDateTimeString(),
                'settled'  => null, // filled by callers that need it
            ];
        }
        return $out;
    }

    /**
     * Map a DB `ordered_at` back to its Ignite week_no.
     * Uses the cached calendar for the timestamp's UTC+8 year.
     */
    private function weekNoFor(Carbon $orderedAt): ?int
    {
        $utc8 = $orderedAt->copy()->setTimezone(self::IGNITE_TZ);
        $calendar = $this->getCalendar($utc8->year);

        foreach ($calendar['months'] ?? [] as $month) {
            foreach ($month['weeks'] ?? [] as $w) {
                $start = Carbon::createFromFormat('Y-m-d H:i:s', $w['start_at'], self::IGNITE_TZ);
                $end   = Carbon::createFromFormat('Y-m-d H:i:s', $w['end_at'],   self::IGNITE_TZ);
                if ($utc8->greaterThanOrEqualTo($start) && $utc8->lessThan($end)) {
                    return (int) $w['week_no'];
                }
            }
        }
        return null;
    }

    // =================================================================
    // BULK QUERIES
    // =================================================================
    /**
     * [category_id => [week_no => amount]] for sales OR returns.
     */
    private function categoryWeekly(string $mode, array $weekRanges): array
    {
        [$start, $end] = $this->window($weekRanges);

        $q = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->where('o.ordered_at', '>=', $start)
            ->where('o.ordered_at', '<',  $end)
            ->whereNull('o.deleted_at')
            ->whereNull('ol.deleted_at');

        if ($mode === 'sales') {
            $q->whereIn('o.status', self::SALE_STATUSES)
              ->select('p.category_id', DB::raw('SUM(ol.line_total) as amt'), 'o.ordered_at');
        } else {
            $q->where('o.status', 'returned')
              ->select('p.category_id', DB::raw('SUM(-ol.line_total) as amt'), 'o.ordered_at');
        }

        $rows = $q->groupBy('p.category_id', 'o.ordered_at')->get();

        $out = [];
        foreach ($rows as $r) {
            $wk = $this->weekNoFor(Carbon::parse($r->ordered_at));
            if ($wk === null) continue;
            $out[$r->category_id][$wk] = ($out[$r->category_id][$wk] ?? 0) + (float) $r->amt;
        }
        return $out;
    }

    /**
     * [product_id => [week_no => value]] for sales (money) OR orders (count).
     */
    private function productWeekly(string $mode, array $weekRanges): array
    {
        [$start, $end] = $this->window($weekRanges);

        $q = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->where('o.ordered_at', '>=', $start)
            ->where('o.ordered_at', '<',  $end)
            ->whereNull('o.deleted_at')
            ->whereNull('ol.deleted_at')
            ->whereIn('o.status', self::SALE_STATUSES);

        if ($mode === 'sales') {
            $q->select('ol.product_id', DB::raw('SUM(ol.line_total) as val'), 'o.ordered_at')
              ->groupBy('ol.product_id', 'o.ordered_at');
        } else {
            $q->select('ol.product_id', DB::raw('COUNT(DISTINCT o.id) as val'), 'o.ordered_at')
              ->groupBy('ol.product_id', 'o.ordered_at');
        }

        $rows = $q->get();

        $out = [];
        foreach ($rows as $r) {
            $wk = $this->weekNoFor(Carbon::parse($r->ordered_at));
            if ($wk === null) continue;
            $out[$r->product_id][$wk] = ($out[$r->product_id][$wk] ?? 0) + (float) $r->val;
        }
        return $out;
    }

    /** Gross / retail weekly totals (orders.total or line_total sum depending on schema). */
    private function grossRetailWeekly(array $weekRanges): array
    {
        [$start, $end] = $this->window($weekRanges);

        $rows = DB::table('orders as o')
            ->where('o.ordered_at', '>=', $start)
            ->where('o.ordered_at', '<',  $end)
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', self::SALE_STATUSES)
            ->select('o.ordered_at', DB::raw('SUM(o.grand_total) as amt'))
            ->groupBy('o.ordered_at')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $wk = $this->weekNoFor(Carbon::parse($r->ordered_at));
            if ($wk === null) continue;
            $out[$wk] = ($out[$wk] ?? 0) + (float) $r->amt;
        }
        return $out;
    }

    /** CV from Ignite pairings, per week. */
    private function cvByWeek(int $year, array $weekNos): array
    {
        $out = [];
        foreach ($weekNos as $w) {
            $out[$w] = Cache::remember("ignite_cv_{$year}_{$w}", 3600, function () use ($year, $w) {
                try {
                    $resp = $this->ignite->pairings($year, $w);
                    if (($resp['code'] ?? null) !== ResultCode::OK->value) return 0.0;
                    return collect($resp['data']['rows'] ?? [])
                        ->sum(fn ($r) => (float) ($r['paired_cv'] ?? 0));
                } catch (\Throwable $e) {
                    Log::warning('Ignite pairings failed', ['year' => $year, 'week' => $w, 'error' => $e->getMessage()]);
                    return 0.0;
                }
            });
        }
        return $out;
    }

    /**
     * Outstanding shipment per category.
     * Assumes a local `ignite_outstanding_shipments` mirror populated from
     * floatingCvTxs() / open order lines. Falls back to zero when the table is missing.
     */
    private function outstandingByCategory(int $year, array $weekRanges): array
    {
        if (!\Schema::hasTable('ignite_outstanding_shipments')) {
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
    // BUILDERS / HELPERS
    // =================================================================
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
                'total'        => $cast === 'money'
                    ? round(array_sum($row), 2)
                    : array_sum($row),
            ];
        }

        return [
            'rows'          => $rows,
            'weekly_totals' => $cast === 'money'
                ? array_map(fn ($v) => round($v, 2), $totals) + ['total' => round(array_sum($totals), 2)]
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

    private function sumByWeek(array $byCategory, array $weekNos): array
    {
        $out = array_fill_keys($weekNos, 0.0);
        foreach ($byCategory as $weekly) {
            foreach ($weekNos as $w) {
                $out[$w] += $weekly[$w] ?? 0;
            }
        }
        return $out;
    }

    private function window(array $weekRanges): array
    {
        $starts = array_map(fn ($r) => $r[0], $weekRanges);
        $ends   = array_map(fn ($r) => $r[1], $weekRanges);
        return [min($starts), max($ends)];
    }

    private function categoryName(int $categoryId): string
    {
        static $cache = [];
        return $cache[$categoryId] ??= (Category::where('id', $categoryId)->value('title') ?? "Category #{$categoryId}");
    }
}