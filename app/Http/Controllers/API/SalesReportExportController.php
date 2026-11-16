<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Category;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReportExportController extends Controller
{
    protected IgniteClient $ignite;

    public function __construct(IgniteClient $ignite)
    {
        $this->ignite = $ignite;
    }

    /**
     * GET /api/finance/sales-report?week=40&year=2026&weeks_back=5
     * 
     * Returns sales data grouped by category and product for the last N weeks
     */
    public function download(Request $request)
    {
        $request->validate([
            'week'       => 'required|integer|min:1|max:53',
            'year'       => 'required|integer|min:2020|max:2100',
            'weeks_back' => 'nullable|integer|min:1|max:10',
        ]);

        $currentWeek = (int) $request->week;
        $year = (int) $request->year;
        $weeksBack = (int) ($request->weeks_back ?? 5);

        // Build list of weeks: [40, 39, 38, 37, 36]
        $weeks = [];
        for ($i = 0; $i < $weeksBack; $i++) {
            $w = $currentWeek - $i;
            if ($w < 1) {
                $w = 52 + $w;
            }
            $weeks[] = $w;
        }

        // Get week ranges from Ignite calendar (cached)
        $calendar = $this->getCalendar($year);

        $weekRanges = [];
        foreach ($weeks as $w) {
            $range = $this->findWeekRange($calendar, $w);
            if ($range) {
                $weekRanges[$w] = $range;
            }
        }

        // ============================================================
        // SECTION 1: Product by Category
        // ============================================================
        $categories = Category::select('id', 'title')
            ->orderBy('title')
            ->get();

        $categorySales = [];
        foreach ($categories as $cat) {
            $weeklySales = [];
            foreach ($weekRanges as $w => $range) {
                [$start, $end] = $range;
                $weeklySales[$w] = $this->getCategorySales($cat->id, $start, $end);
            }
            $categorySales[] = [
                'category_id'   => $cat->id,
                'category_name' => $cat->title,
                'weekly_sales'  => $weeklySales,
            ];
        }

        // ============================================================
        // SECTION 2: Product by Product (Sales Amount)
        // ============================================================
        $products = Product::select('id', 'name', 'category_id')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $productSales = [];
        foreach ($products as $prod) {
            $weeklySales = [];
            foreach ($weekRanges as $w => $range) {
                [$start, $end] = $range;
                $weeklySales[$w] = $this->getProductSales($prod->id, $start, $end);
            }
            $productSales[] = [
                'product_id'   => $prod->id,
                'product_name' => $prod->name,
                'weekly_sales' => $weeklySales,
            ];
        }

        // ============================================================
        // SECTION 3: Number of Orders (per product per week)
        // ============================================================
        $productOrders = [];
        foreach ($products as $prod) {
            $weeklyOrders = [];
            foreach ($weekRanges as $w => $range) {
                [$start, $end] = $range;
                $weeklyOrders[$w] = $this->getProductOrderCount($prod->id, $start, $end);
            }
            $productOrders[] = [
                'product_id'    => $prod->id,
                'product_name'  => $prod->name,
                'weekly_orders' => $weeklyOrders,
            ];
        }

        return response()->json([
            'success' => true,
            'filters' => [
                'year'       => $year,
                'week'       => $currentWeek,
                'weeks_back' => $weeksBack,
                'weeks'      => $weeks,
            ],
            'sections' => [
                'product_by_category' => $categorySales,
                'product_by_product'  => $productSales,
                'number_of_orders'    => $productOrders,
            ],
        ]);
    }

    /**
     * Get week range from Ignite calendar
     */
    private function getCalendar(int $year): array
    {
        return Cache::remember("ignite_calendar_{$year}", 86400, function () use ($year) {
            $resp = $this->ignite->salesCalendar($year);
            return ($resp['code'] ?? null) === ResultCode::OK->value ? $resp['data'] : [];
        });
    }

    /**
     * Find start_at/end_at for a given week number
     */
    private function findWeekRange(array $calendar, int $weekNo): ?array
    {
        $months = $calendar['months'] ?? [];
        foreach ($months as $month) {
            foreach ($month['weeks'] ?? [] as $w) {
                if ((int) $w['week_no'] === $weekNo) {
                    return [
                        Carbon::parse($w['start_at'], 'Asia/Hong_Kong')->setTimezone('Asia/Kolkata'),
                        Carbon::parse($w['end_at'], 'Asia/Hong_Kong')->setTimezone('Asia/Kolkata'),
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Get total sales amount for a category in a week
     */
    private function getCategorySales(int $categoryId, Carbon $start, Carbon $end): float
    {
        return (float) OrderLine::whereHas('order', function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->whereNull('deleted_at')
                  ->whereIn('status', ['confirmed', 'dispatched', 'shipped', 'delivered', 'partial_delivered']);
            })
            ->whereHas('product', fn($q) => $q->where('category_id', $categoryId))
            ->sum('line_total');
    }

    /**
     * Get total sales amount for a product in a week
     */
    private function getProductSales(int $productId, Carbon $start, Carbon $end): float
    {
        return (float) OrderLine::whereHas('order', function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->whereNull('deleted_at')
                  ->whereIn('status', ['confirmed', 'dispatched', 'shipped', 'delivered', 'partial_delivered']);
            })
            ->where('product_id', $productId)
            ->sum('line_total');
    }

    /**
     * Get number of orders for a product in a week (distinct orders, not lines)
     */
    private function getProductOrderCount(int $productId, Carbon $start, Carbon $end): int
    {
        return (int) OrderLine::whereHas('order', function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->whereNull('deleted_at')
                  ->whereIn('status', ['confirmed', 'dispatched', 'shipped', 'delivered', 'partial_delivered']);
            })
            ->where('product_id', $productId)
            ->distinct('order_id')
            ->count('order_id');
    }
}