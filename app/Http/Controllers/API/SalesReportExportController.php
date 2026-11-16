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

    /**
     * Sales Report — JSON (CSV format ke exact columns)
     *
     * Query params:
     *  - status    : confirmed|dispatched|shipped|delivered (optional)
     *  - from      : YYYY-MM-DD
     *  - to        : YYYY-MM-DD
     *  - per_page  : default 100
     */
    public function salesReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => 'nullable|string|in:confirmed,dispatched,shipped,delivered',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            // Default: sales = confirmed + dispatched + shipped + delivered
            $allowedStatuses = $request->filled('status')
                ? [$request->status]
                : ['confirmed', 'dispatched', 'shipped', 'delivered'];

            $perPage = (int) $request->input('per_page', 100);

            // ============================================================
            // BASE QUERY — order line level (sales line-wise hoti hai)
            // ============================================================
            $query = OrderLine::query()
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->leftJoin('users', 'users.id', '=', 'orders.user_id')
                ->leftJoin('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('order_shipping_details', 'order_shipping_details.order_line_id', '=', 'order_lines.id')
                ->leftJoin('order_addresses as delivery_addr', function ($join) {
                    $join->on('delivery_addr.order_id', '=', 'orders.id')
                        ->where('delivery_addr.type', '=', 'delivery');
                })
                ->whereIn('orders.status', $allowedStatuses)
                ->whereNull('orders.deleted_at');

            // Date filters
            if ($request->filled('from')) {
                $query->where('orders.created_at', '>=', $request->from . ' 00:00:00');
            }
            if ($request->filled('to')) {
                $query->where('orders.created_at', '<=', $request->to . ' 23:59:59');
            }

            // ============================================================
            // SELECT — CSV columns ke exact mapping
            // ============================================================
            $query->select([
                'orders.order_reference                                    AS bill_id',
                'orders.created_at                                         AS order_date',
                'users.full_name                                            AS customer_name',
                'users.phone                                                AS customer_mobile',
                'orders.subtotal                                            AS gross_total',
                'orders.total_payable                                       AS order_amount',
                'orders.refunded_amount                                     AS refunded_amount',
                'orders.total_payable                                       AS net_order_amount',
                'orders.status                                              AS order_status',
                DB::raw('COUNT(order_lines.id) OVER (PARTITION BY orders.id) AS total_items'),
                'orders.total_gst                                           AS total_tax',
                'orders.total_cgst                                          AS cgst',
                'orders.total_sgst                                          AS sgst',
                'orders.total_igst                                          AS igst',
                'orders.subtotal                                            AS total_list_price',
                'orders.coupon_discount                                     AS discount_amount',
                'order_lines.commissionable_volume                          AS cv',
                'users.distributor_id                                       AS user_name',
                'users.gstin                                                AS customer_gstin',
                'order_shipping_details.shipped_at                          AS shipping_date',
                'delivery_addr.state                                        AS customer_shipping_state',
                'order_lines.shipping_charge                                AS shipping_charge',
                'order_lines.delivery_status                                AS delivery_status',
                DB::raw("CASE WHEN COALESCE(orders.refunded_amount,0) > 0 THEN 'TRUE' ELSE 'FALSE' END AS is_refunded"),
                'orders.refund_status                                       AS refund_status',
                DB::raw('0 AS refunded_items'),
                'orders.last_refund_date                                    AS last_refund_date',
            ])
            ->orderBy('orders.created_at', 'desc')
            ->orderBy('order_lines.id', 'asc');

            $paginated = $query->paginate($perPage);

            // ============================================================
            // FORMAT — CSV jaisa hi JSON keys
            // ============================================================
            $data = $paginated->getCollection()->map(function ($row) {
                return [
                    'Bill Id'                 => (string) $row->bill_id,
                    'Order Date'              => $row->order_date
                        ? \Carbon\Carbon::parse($row->order_date)->format('d-m-Y H:i') : '',
                    'Customer Name'           => $row->customer_name ?? '',
                    'Customer Mobile'         => $row->customer_mobile ?? '',
                    'Gross Total'             => round((float) $row->gross_total, 2),
                    'Order Amount'            => round((float) $row->order_amount, 2),
                    'Refunded Amount'         => round((float) ($row->refunded_amount ?? 0), 2),
                    'Net Order Amount'        => round((float) $row->net_order_amount, 2),
                    'Order Status'            => $row->order_status,
                    'Total Items'             => (int) $row->total_items,
                    'Total Tax'               => round((float) $row->total_tax, 2),
                    'CGST'                    => round((float) $row->cgst, 2),
                    'SGST'                    => round((float) $row->sgst, 2),
                    'IGST'                    => round((float) $row->igst, 2),
                    'Total List Price'        => round((float) $row->total_list_price, 2),
                    'Discount Amount'         => round((float) $row->discount_amount, 2),
                    'CV'                      => round((float) $row->cv, 2),
                    'User Name'               => $row->user_name ?? '',
                    'Customer GSTIN'          => $row->customer_gstin ?? '',
                    'Shipping Date'           => $row->shipping_date
                        ? \Carbon\Carbon::parse($row->shipping_date)->format('d-m-Y H:i') : '',
                    'Customer Shipping State' => $row->customer_shipping_state ?? '',
                    'Shipping Charge'         => round((float) $row->shipping_charge, 2),
                    'Delivery Status'         => $row->delivery_status ?? '',
                    'Is Refunded'             => $row->is_refunded,
                    'Refund Status'           => $row->refund_status ?? '',
                    'Refunded Items'          => (int) $row->refunded_items,
                    'Last Refund Date'        => $row->last_refund_date
                        ? \Carbon\Carbon::parse($row->last_refund_date)->format('d-m-Y H:i') : '',
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data,
                'meta'    => [
                    'total'        => $paginated->total(),
                    'per_page'     => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Sales report failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

}