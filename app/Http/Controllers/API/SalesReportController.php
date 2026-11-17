<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class SalesReportController extends Controller
{
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
            $allowedStatuses = $request->filled('status')
                ? [$request->status]
                : ['confirmed', 'dispatched', 'shipped', 'delivered'];

            $perPage = (int) $request->input('per_page', 100);

            // ============================================================
            // Subqueries: refunds aur GSTIN
            // ============================================================
            $refundSub = DB::table('refunds')
                ->select('order_id')
                ->selectRaw('SUM(amount) AS total_refunded')
                ->selectRaw('MAX(completed_at) AS last_refunded_at')
                ->where('status', 'completed')
                ->groupBy('order_id');

            $gstinSub = DB::table('distributor_profiles')
                ->select('user_id')
                ->selectRaw('MAX(gst_in) AS gst_in')
                ->whereNotNull('gst_in')
                ->groupBy('user_id');

            // ============================================================
            // BASE QUERY
            // ============================================================
            $query = OrderLine::query()
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->leftJoin('users', 'users.id', '=', 'orders.user_id')
                ->leftJoin('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoinSub($refundSub, 'refund_sum', function ($join) {
                    $join->on('refund_sum.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($gstinSub, 'gstin_data', function ($join) {
                    $join->on('gstin_data.user_id', '=', 'users.id');
                })
                ->leftJoin('addresses as delivery_addr', function ($join) {
                    $join->on('delivery_addr.user_id', '=', 'orders.user_id')
                        ->where('delivery_addr.is_delivery', '=', 1);
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
            // SELECT
            // ============================================================
            $query->select([
                'orders.order_reference                                    AS bill_id',
                'orders.created_at                                         AS order_date',
                'users.full_name                                            AS customer_name',
                'users.phone                                                AS customer_mobile',
                'orders.subtotal                                            AS gross_total',
                'orders.total_payable                                       AS order_amount',

                // Refunded amount — refunds table se
                DB::raw('COALESCE(refund_sum.total_refunded, 0) AS refunded_amount'),

                // Net = order amount - refunded
                DB::raw('(orders.total_payable - COALESCE(refund_sum.total_refunded, 0)) AS net_order_amount'),

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

                // GSTIN — distributor_profiles se
                'gstin_data.gst_in                                          AS customer_gstin',

                // Shipping date — order_lines.shipped_at se
                'order_lines.shipped_at                                     AS shipping_date',

                // Shipping state — addresses se
                'delivery_addr.state                                        AS customer_shipping_state',

                'order_lines.shipping_charge                                AS shipping_charge',
                'order_lines.delivery_status                                AS delivery_status',

                // Refund flags
                DB::raw("CASE WHEN COALESCE(refund_sum.total_refunded, 0) > 0 THEN 'TRUE' ELSE 'FALSE' END AS is_refunded"),
                'orders.refund_status                                       AS refund_status',
                DB::raw('0 AS refunded_items'),
                DB::raw('refund_sum.last_refunded_at AS last_refund_date'),
            ])
                ->orderBy('orders.created_at', 'desc')
                ->orderBy('order_lines.id', 'asc');

            $paginated = $query->paginate($perPage);

            // ============================================================
            // FORMAT — CSV jaisa hi JSON
            // ============================================================
            $data = $paginated->getCollection()->map(function ($row) {
                return [
                    'Bill Id'                 => (string) $row->bill_id,
                    'Order Date'              => $row->order_date
                        ? Carbon::parse($row->order_date)->format('d-m-Y H:i') : '',
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
                        ? Carbon::parse($row->shipping_date)->format('d-m-Y H:i') : '',
                    'Customer Shipping State' => $row->customer_shipping_state ?? '',
                    'Shipping Charge'         => round((float) $row->shipping_charge, 2),
                    'Delivery Status'         => $row->delivery_status ?? '',
                    'Is Refunded'             => $row->is_refunded,
                    'Refund Status'           => $row->refund_status ?? '',
                    'Refunded Items'          => (int) $row->refunded_items,
                    'Last Refund Date'        => $row->last_refund_date
                        ? Carbon::parse($row->last_refund_date)->format('d-m-Y H:i') : '',
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
            Log::error('Sales report failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
