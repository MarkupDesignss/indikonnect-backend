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

    public function deliveryeportsr(Request $request)
    {
        // Only these 4 statuses allowed
        $allowedStatuses = ['confirmed', 'dispatched', 'shipped', 'delivered'];

        $query = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('invoices as inv', 'inv.order_line_id', '=', 'ol.id')
            // Shipping details (one row per order_line ideally)
            ->leftJoin('order_shipping_details as osd', 'osd.order_line_id', '=', 'ol.id')
            ->select([
                // ----- Shipping details -----
                'osd.courier_tracking_number   as shipment_number',
                'osd.courier_company           as carrier',
                'osd.courier_delivery_date     as shipment_date',
                'osd.status                    as courier_status',
                'osd.delivery_notes            as shipping_delivery_notes',

                // ----- Invoice info -----
                'inv.invoice_number            as invoice_number',
                'inv.issued_at                 as invoice_issued_at',
                'inv.seller_name               as seller_name',
                'inv.seller_gstin              as seller_gstin',
                'inv.buyer_name                as buyer_name',
                'inv.buyer_gstin               as buyer_gstin',
                'inv.delivery_state            as delivery_state',
                'inv.total_taxable             as total_taxable',
                'inv.total_tax                 as total_tax',
                'inv.total                     as invoice_total',
                'inv.total_payable             as invoice_total_payable',
                'inv.coupon_code               as coupon_code',
                'inv.coupon_discount           as coupon_discount',
                'inv.shipping_charge           as invoice_shipping_charge',
                'inv.pdf_path                  as invoice_pdf_path',
                'inv.eway_bill_no              as eway_bill_no',
                'inv.eway_bill_date            as eway_bill_date',
                'inv.eway_bill_status          as eway_bill_status',

                // ----- Order / Bill info -----
                'o.id                          as bill_id',
                'o.order_reference             as order_reference',
                'o.ignite_order_no             as ignite_order_no',
                'o.created_at                  as order_date',

                // ----- Item info -----
                'p.name                        as item_name',
                'p.product_code                as sku',
                'p.hsn_code                    as hsn_code',
                'ol.quantity                   as qty',
                'ol.unit_price                 as unit_price',
                'ol.line_total                 as line_total',
                'ol.gst_rate                   as gst_rate',
                'ol.gst_amount                 as gst_amount',

                // ----- Delivery info (from order_lines) -----
                'ol.delivery_status            as delivery_status',
                'ol.created_at              as created_at',
                'ol.dispatched_at              as dispatched_at',
                'ol.shipped_at                 as shipped_at',
                'ol.delivered_at               as delivered_at',
                'ol.delivery_notes             as line_delivery_notes',

                // ----- Recipient -----
                'u.full_name                   as recipient_name',
                'u.phone                       as recipient_phone',
                'u.email                       as recipient_email',
            ])
            ->whereIn(DB::raw('LOWER(ol.delivery_status)'), $allowedStatuses);

        // ---------- Optional filters ----------

        if ($request->filled('from_date')) {
            $query->whereDate('o.created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('o.created_at', '<=', $request->to_date);
        }
        if ($request->filled('delivery_status')) {
            $status = strtolower($request->delivery_status);
            if (in_array($status, $allowedStatuses)) {
                $query->where(DB::raw('LOWER(ol.delivery_status)'), $status);
            }
        }
        if ($request->filled('sku')) {
            $query->where('p.product_code', $request->sku);
        }
        if ($request->filled('order_id')) {
            $query->where('o.id', $request->order_id);
        }
        if ($request->filled('invoice_number')) {
            $query->where('inv.invoice_number', $request->invoice_number);
        }
        if ($request->filled('courier_tracking_number')) {
            $query->where('osd.courier_tracking_number', $request->courier_tracking_number);
        }
        if ($request->filled('courier_company')) {
            $query->where('osd.courier_company', $request->courier_company);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('u.full_name', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%")
                    ->orWhere('inv.invoice_number', 'like', "%{$search}%")
                    ->orWhere('osd.courier_tracking_number', 'like', "%{$search}%");
            });
        }

        // ---------- Sorting ----------
        $sortBy  = $request->get('sort_by', 'o.created_at');
        $sortDir = strtolower($request->get('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSortColumns = [
            'bill_id',
            'invoice_number',
            'order_date',
            'item_name',
            'sku',
            'qty',
            'delivery_status',
            'shipment_date',
            'recipient_name',
            'carrier',
            'invoice_issued_at',
        ];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('o.created_at', 'desc');
        }

        // ---------- Pagination ----------
        $perPage = (int) $request->get('per_page', 500);
        $perPage = min(max($perPage, 1), 5000);

        $rows = $query->paginate($perPage);

        // ---------- Format response ----------
        $data = collect($rows->items())->map(function ($row) {
            return [
                // Invoice
                'Invoice Number'          => $row->invoice_number,
                // 'Invoice Issued At'       => $row->invoice_issued_at,
                // 'Seller Name'             => $row->seller_name,
                // 'Seller GSTIN'            => $row->seller_gstin,
                // 'Buyer Name'              => $row->buyer_name,
                // 'Buyer GSTIN'             => $row->buyer_gstin,
                // 'Delivery State'          => $row->delivery_state,
                // 'Total Taxable'           => $row->total_taxable,
                // 'Total Tax'               => $row->total_tax,
                // 'Invoice Total'           => $row->invoice_total,
                // 'Invoice Total Payable'   => $row->invoice_total_payable,
                // 'Coupon Code'             => $row->coupon_code,
                // 'Coupon Discount'         => $row->coupon_discount,
                // 'Invoice Shipping Charge' => $row->invoice_shipping_charge,
                // 'Invoice PDF Path'        => $row->invoice_pdf_path,
                // 'E-Way Bill No'           => $row->eway_bill_no,
                // 'E-Way Bill Date'         => $row->eway_bill_date,
                // 'E-Way Bill Status'       => $row->eway_bill_status,

                // Order
                // 'Bill Id'                 => $row->bill_id,
                'Order Reference'         => $row->order_reference,
                // 'Ignite Order No'         => $row->ignite_order_no,
                // 'Order Date'              => $row->order_date,

                // Item
                'Item Name'               => $row->item_name,
                'SKU'                     => $row->sku,
                // 'HSN Code'                => $row->hsn_code,
                'QTY'                     => $row->qty,
                // 'Unit Price'              => $row->unit_price,
                // 'Line Total'              => $row->line_total,
                // 'GST Rate'                => $row->gst_rate,
                // 'GST Amount'              => $row->gst_amount,

                // Delivery
                'Delivery Status'         => ucfirst($row->delivery_status),
                'Placed At'               => $row->created_at,
                'Dispatched At'           => $row->dispatched_at,
                'Shipped At'              => $row->shipped_at,
                'Delivered At'            => $row->delivered_at,
                // 'Delivery Notes'          => $row->shipping_delivery_notes ?: $row->line_delivery_notes,

                // Courier / Shipment (now from order_shipping_details)
                'Shipment Number'         => $row->shipment_number,
                // 'Shipment Date'           => $row->shipment_date,
                'Carrier'                 => $row->carrier,
                // 'Courier Status'          => $row->courier_status,

                // Recipient
                'Recipient Name'          => $row->recipient_name,
                'Recipient Phone'         => $row->recipient_phone,
                'Recipient Email'         => $row->recipient_email,
            ];
        });

        return response()->json([
            'status'  => true,
            'message' => 'Delivery report fetched successfully',
            'filters' => [
                'allowed_statuses' => $allowedStatuses,
                'from_date' => $request->from_date,
                'to_date'   => $request->to_date,
            ],
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
                'last_page'    => $rows->lastPage(),
            ],
            'data' => $data,
        ]);
    }

    public function returnreports(Request $request)
    {
        $query = DB::table('returns as r')
            ->leftJoin('refunds as rf', function ($join) {
                // Latest refund per return (by max id)
                $join->on('rf.return_id', '=', 'r.id')
                    ->whereRaw('rf.id = (SELECT MAX(id) FROM refunds WHERE return_id = r.id)');
            })
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('order_lines as ol', 'ol.id', '=', 'r.order_line_id')
            ->leftJoin('products as p', 'p.id', '=', 'ol.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')  // recipient
            ->select([
                // ----- Refund (bill id = refunds.id) -----
                'rf.id                      as refund_bill_id',
                'rf.amount                  as refund_amount',
                'rf.adjusted_amount         as refund_adjusted_amount',
                'rf.status                  as refund_status',
                'rf.refund_method           as refund_method',
                'rf.gateway_reference       as refund_gateway_reference',
                'rf.completed_at            as refund_date',
                'rf.failure_reason          as refund_failure_reason',
                'rf.notes                   as refund_notes',

                // ----- Original order (bill id = order_reference) -----
                'o.id                       as original_order_id',
                'o.order_reference          as original_bill_id',
                'o.ignite_order_no          as ignite_order_no',
                'o.created_at               as order_date',

                // ----- Return -----
                'r.id                       as return_id',
                'r.type                     as return_type',
                'r.status                   as return_status',
                'r.resolution               as return_resolution',
                'r.reason                   as return_reason',
                'r.refund_subtotal          as refund_subtotal',
                'r.refund_tax               as refund_tax',
                'r.refund_shipping          as refund_shipping',
                'r.refund_gateway_charges   as refund_gateway_charges',
                'r.refund_extra_deductions  as refund_extra_deductions',
                'r.total_refund_amount      as total_refund_amount',
                'r.tracking_id              as return_tracking_id',
                'r.courier                  as return_courier',
                'r.received_at              as return_received_at',
                'r.completed_at             as return_completed_at',
                'r.created_at               as return_created_at',

                // ----- Item -----
                'p.name                     as item_name',
                'p.product_code             as sku',
                'ol.quantity                as qty',
                'ol.unit_price              as unit_price',
                'ol.line_total              as line_total',

                // ----- Distributor (user_name = distributor id) -----
                'u.id                       as distributor_id',
                'u.full_name                as customer_name',
                'u.phone                    as contact_number',
                'u.email                    as email',
            ]);

        // ---------- Optional filters ----------

        if ($request->filled('from_date')) {
            $query->whereDate('r.created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('r.created_at', '<=', $request->to_date);
        }
        if ($request->filled('return_status')) {
            $query->where('r.status', $request->return_status);
        }
        if ($request->filled('refund_status')) {
            $query->where('rf.status', $request->refund_status);
        }
        if ($request->filled('return_type')) {
            $query->where('r.type', $request->return_type);
        }
        if ($request->filled('order_id')) {
            $query->where('r.order_id', $request->order_id);
        }
        if ($request->filled('distributor_id')) {
            $query->where('u.id', $request->distributor_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('u.full_name', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%")
                    ->orWhere('o.order_reference', 'like', "%{$search}%")
                    ->orWhere('p.product_code', 'like', "%{$search}%")
                    ->orWhere('rf.id', 'like', "%{$search}%");
            });
        }

        // ---------- Sorting ----------
        $sortBy  = $request->get('sort_by', 'r.created_at');
        $sortDir = strtolower($request->get('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSortColumns = [
            'refund_bill_id',
            'original_bill_id',
            'refund_amount',
            'refund_date',
            'distributor_id',
            'customer_name',
            'contact_number',
            'return_status',
            'item_name',
            'sku',
        ];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('r.created_at', 'desc');
        }

        // ---------- Pagination ----------
        $perPage = (int) $request->get('per_page', 500);
        $perPage = min(max($perPage, 1), 5000);

        $rows = $query->paginate($perPage);

        // ---------- Format response (Excel-like) ----------
        $data = collect($rows->items())->map(function ($row) {
            return [
                // Mapping your CSV headers with requested corrections:
                'Refund Bill Id'         => $row->refund_bill_id,           // refunds.id
                'Original Bill Id'       => $row->original_bill_id,         // order_reference
                'Refund Amount'          => $row->refund_amount ?? $row->total_refund_amount,
                'Reason'                 => $row->return_reason,
                'Refund Date'            => $row->refund_date,
                'Distributor Id'         => $row->distributor_id,           // users.id (was User Name)
                'Customer Name'          => $row->customer_name,
                'Contact Number'         => $row->contact_number,

                // Extra useful columns
                'Ignite Order No'        => $row->ignite_order_no,
                'Order Date'             => $row->order_date,
                'Return Id'              => $row->return_id,
                'Return Type'            => $row->return_type,
                'Return Status'          => $row->return_status,
                'Return Resolution'      => $row->return_resolution,
                'Return Tracking Id'     => $row->return_tracking_id,
                'Return Courier'         => $row->return_courier,
                'Return Received At'     => $row->return_received_at,
                'Return Completed At'    => $row->return_completed_at,

                'Item Name'              => $row->item_name,
                'SKU'                    => $row->sku,
                'QTY'                    => $row->qty,
                'Unit Price'             => $row->unit_price,
                'Line Total'             => $row->line_total,

                'Refund Subtotal'        => $row->refund_subtotal,
                'Refund Tax'             => $row->refund_tax,
                'Refund Shipping'        => $row->refund_shipping,
                'Refund Gateway Charges' => $row->refund_gateway_charges,
                'Refund Extra Deductions' => $row->refund_extra_deductions,
                'Total Refund Amount'    => $row->total_refund_amount,
                'Refund Adjusted Amount' => $row->refund_adjusted_amount,
                'Refund Status'          => $row->refund_status,
                'Refund Method'          => $row->refund_method,
                'Refund Gateway Ref'     => $row->refund_gateway_reference,
                'Refund Failure Reason'  => $row->refund_failure_reason,
                'Refund Notes'           => $row->refund_notes,
                'Email'                  => $row->email,
            ];
        });

        return response()->json([
            'status'  => true,
            'message' => 'Returns report fetched successfully',
            'filters' => [
                'from_date'     => $request->from_date,
                'to_date'       => $request->to_date,
                'return_status' => $request->return_status,
                'refund_status' => $request->refund_status,
            ],
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
                'last_page'    => $rows->lastPage(),
            ],
            'data' => $data,
        ]);
    }
}
