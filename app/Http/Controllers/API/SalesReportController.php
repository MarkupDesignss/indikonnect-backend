<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
            'status'   => 'nullable|string|in:confirmed,dispatched,shipped,delivered,partial_dispatched,partial_shipped,partial_delivered',
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
                : [
                    'confirmed',
                    'dispatched',
                    'shipped',
                    'delivered',
                    'partial_dispatched',
                    'partial_shipped',
                    'partial_delivered',
                ];

            $perPage = (int) $request->input('per_page', 100);

            // Refund subquery
            $refundSub = DB::table('refunds')
                ->select('order_id')
                ->selectRaw('SUM(amount) AS total_refunded')
                ->selectRaw('MAX(completed_at) AS last_refunded_at')
                ->selectRaw('MAX(created_at) AS first_refund_created_at')
                ->where('status', 'completed')
                ->groupBy('order_id');

            // GSTIN subquery
            $gstinSub = DB::table('distributor_profiles')
                ->select('user_id')
                ->selectRaw('MAX(gst_in) AS gst_in')
                ->whereNotNull('gst_in')
                ->groupBy('user_id');

            /*
         * INVOICE AGGREGATE — KEYED BY order_line_id (per item)
         */
            $invoiceSub = DB::table('invoices')
                ->select('order_line_id')
                ->selectRaw('MIN(invoice_number) AS invoice_number')
                ->selectRaw('MIN(created_at) AS invoice_created_at')
                ->whereNotNull('order_line_id')
                ->groupBy('order_line_id');

            /*
         * LINE AGGREGATE SUBQUERY
         *
         * NO GST EXCLUSION — all amounts stay gross / as stored.
         */
            $lineAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->leftJoin('addresses as delivery_addr', 'delivery_addr.id', '=', 'orders.delivery_address_id')
                ->leftJoin('addresses as billing_addr', 'billing_addr.id', '=', 'orders.billing_address_id')

                ->select('order_lines.order_id')

                ->selectRaw('SUM(order_lines.quantity) AS total_items')
                ->selectRaw('SUM(order_lines.quantity * COALESCE(products.commission_value, 0)) AS total_cv')

                // Gross amounts only
                ->selectRaw('SUM(COALESCE(order_lines.line_total, 0)) AS product_gross')
                ->selectRaw('SUM(COALESCE(order_lines.shipping_charge, 0)) AS shipping_gross')

                ->selectRaw('MAX(order_lines.shipped_at) AS max_shipped_at')
                ->selectRaw('MAX(order_lines.dispatched_at) AS max_dispatched_at')
                ->selectRaw('MAX(order_lines.delivered_at) AS max_delivered_at')
                ->selectRaw('MIN(order_lines.created_at) AS min_line_created_at')

                ->selectRaw('MAX(delivery_addr.state) AS delivery_state')
                ->selectRaw('MAX(billing_addr.state) AS billing_state')

                // Delivery address block
                ->selectRaw('MAX(delivery_addr.id) AS delivery_address_id')
                ->selectRaw('MAX(delivery_addr.recipient_name) AS delivery_recipient_name')
                ->selectRaw('MAX(delivery_addr.contact_number) AS delivery_contact_number')
                ->selectRaw('MAX(delivery_addr.address_line_1) AS delivery_address_line_1')
                ->selectRaw('MAX(delivery_addr.address_line_2) AS delivery_address_line_2')
                ->selectRaw('MAX(delivery_addr.city) AS delivery_city')
                ->selectRaw('MAX(delivery_addr.post_office) AS delivery_post_office')
                ->selectRaw('MAX(delivery_addr.postcode) AS delivery_postcode')
                ->selectRaw('MAX(delivery_addr.country) AS delivery_country')

                // Billing address block
                ->selectRaw('MAX(billing_addr.id) AS billing_address_id')
                ->selectRaw('MAX(billing_addr.billing_recipient_name) AS billing_recipient_name')
                ->selectRaw('MAX(billing_addr.billing_contact_number) AS billing_contact_number')
                ->selectRaw('MAX(billing_addr.billing_address_line_1) AS billing_address_line_1')
                ->selectRaw('MAX(billing_addr.billing_address_line_2) AS billing_address_line_2')
                ->selectRaw('MAX(billing_addr.billing_city) AS billing_city')
                ->selectRaw('MAX(billing_addr.billing_post_office) AS billing_post_office')
                ->selectRaw('MAX(billing_addr.billing_postcode) AS billing_postcode')
                ->selectRaw('MAX(billing_addr.billing_country) AS billing_country')

                // Overall delivery status incl. partial statuses
                ->selectRaw("
                CASE
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) = COUNT(*) THEN 'delivered'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) > 0 THEN 'partial_delivered'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'shipped' THEN 1 ELSE 0 END) > 0
                        AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) > 0
                        THEN 'partial_shipped'
                    WHEN SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) = 0 THEN 'shipped'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0
                        AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('dispatched','shipped','delivered') THEN 1 ELSE 0 END) > 0
                        THEN 'partial_dispatched'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0 THEN 'dispatched'
                    ELSE 'confirmed'
                END AS overall_delivery_status
            ")

                ->groupBy('order_lines.order_id');

            /*
         * PRODUCTS SUBQUERY — joined to invoice per line.
         *
         * Layout (12 fields) — NO GST-derived fields:
         *  0  order_line_id
         *  1  item_reference_id
         *  2  name
         *  3  slug
         *  4  product_code
         *  5  hsn_code
         *  6  quantity
         *  7  unit_price       (as stored, GST-inclusive)
         *  8  line_total       (as stored, GST-inclusive)
         *  9  gst_rate
         *  10 shipping_charge  (as stored, GST-inclusive)
         *  11 invoice_number
         *  12 invoice_created_at
         */
            $productAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                ->leftJoinSub($invoiceSub, 'invoice_data', function ($join) {
                    $join->on('invoice_data.order_line_id', '=', 'order_lines.id');
                })
                ->select('order_lines.order_id')
                ->selectRaw("
                GROUP_CONCAT(
                    CONCAT_WS('::',
                        COALESCE(order_lines.id, 0),
                        COALESCE(order_lines.item_reference_id, ''),
                        COALESCE(products.name, ''),
                        COALESCE(products.slug, ''),
                        COALESCE(products.product_code, ''),
                        COALESCE(products.hsn_code, ''),
                        COALESCE(order_lines.quantity, 0),
                        COALESCE(order_lines.unit_price, 0),
                        COALESCE(order_lines.line_total, 0),
                        COALESCE(order_lines.gst_rate, tax_categories.rate, 0),
                        COALESCE(order_lines.shipping_charge, 0),
                        COALESCE(invoice_data.invoice_number, ''),
                        COALESCE(invoice_data.invoice_created_at, '')
                    )
                    ORDER BY products.name SEPARATOR '||'
                ) AS products_concat
            ")
                ->groupBy('order_lines.order_id');

            // LINE SHIPPING DETAILS SUBQUERY (per line)
            $shippingAggSub = DB::table('order_shipping_details as osd')
                ->join('order_lines as ol', 'ol.id', '=', 'osd.order_line_id')
                ->select('ol.order_id')
                ->selectRaw("
                GROUP_CONCAT(
                    CONCAT_WS('::',
                        COALESCE(osd.order_line_id, 0),
                        COALESCE(osd.courier_company, ''),
                        COALESCE(osd.courier_tracking_number, '')
                    )
                    ORDER BY osd.id SEPARATOR '||'
                ) AS courier_concat
            ")
                ->groupBy('ol.order_id');

            /*
         * BASE QUERY
         */
            $query = Order::query()
                ->join('users', 'users.id', '=', 'orders.user_id')
                ->leftJoinSub($refundSub, 'refund_sum', function ($join) {
                    $join->on('refund_sum.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($gstinSub, 'gstin_data', function ($join) {
                    $join->on('gstin_data.user_id', '=', 'users.id');
                })
                ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
                    $join->on('line_agg.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($productAggSub, 'product_agg', function ($join) {
                    $join->on('product_agg.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($shippingAggSub, 'shipping_agg', function ($join) {
                    $join->on('shipping_agg.order_id', '=', 'orders.id');
                })
                ->whereIn('line_agg.overall_delivery_status', $allowedStatuses)
                ->whereIn('orders.status', $allowedStatuses)   // excludes pending
                ->whereNull('orders.deleted_at');

            if ($request->filled('from')) {
                $query->where('orders.created_at', '>=', $request->from . ' 00:00:00');
            }
            if ($request->filled('to')) {
                $query->where('orders.created_at', '<=', $request->to . ' 23:59:59');
            }

            // SELECT (order-level fields only — no GST breakdown)
            $query->select([
                'orders.order_reference                    AS order_reference',
                'orders.created_at                         AS order_date',
                'users.full_name                           AS customer_name',
                'users.phone                               AS customer_mobile',
                'users.email                               AS customer_email',
                'users.account_type                        AS account_type',

                'orders.subtotal                           AS gross_total',
                'orders.total_payable                      AS order_amount',
                DB::raw('COALESCE(refund_sum.total_refunded, 0) AS refunded_amount'),
                DB::raw('(orders.total_payable - COALESCE(refund_sum.total_refunded, 0)) AS net_order_amount'),

                'orders.status                             AS order_status',
                DB::raw('COALESCE(line_agg.total_items, 0) AS total_items'),

                // Gross amounts only — no GST split
                DB::raw('COALESCE(line_agg.product_gross, 0) AS product_gross'),
                DB::raw('COALESCE(line_agg.shipping_gross, 0) AS shipping_gross'),

                'orders.subtotal                           AS total_list_price',
                'orders.coupon_discount                    AS discount_amount',
                DB::raw('COALESCE(line_agg.total_cv, 0) AS cv'),
                'users.distributor_id                      AS user_name',
                'gstin_data.gst_in                         AS customer_gstin',

                'line_agg.max_shipped_at                   AS shipping_date',
                'line_agg.min_line_created_at              AS confirmed_at',
                'line_agg.max_dispatched_at                AS dispatched_at',
                'line_agg.max_delivered_at                 AS delivered_at',

                'line_agg.billing_state',
                'line_agg.delivery_state',

                // Delivery address
                'line_agg.delivery_address_id',
                'line_agg.delivery_recipient_name',
                'line_agg.delivery_contact_number',
                'line_agg.delivery_address_line_1',
                'line_agg.delivery_address_line_2',
                'line_agg.delivery_city',
                'line_agg.delivery_post_office',
                'line_agg.delivery_postcode',
                'line_agg.delivery_country',

                // Billing address
                'line_agg.billing_address_id',
                'line_agg.billing_recipient_name',
                'line_agg.billing_contact_number',
                'line_agg.billing_address_line_1',
                'line_agg.billing_address_line_2',
                'line_agg.billing_city',
                'line_agg.billing_post_office',
                'line_agg.billing_postcode',
                'line_agg.billing_country',

                DB::raw("CASE WHEN orders.status = 'delivered' THEN 'delivered' ELSE COALESCE(line_agg.overall_delivery_status, 'confirmed') END AS delivery_status"),
                DB::raw("CASE WHEN COALESCE(refund_sum.total_refunded, 0) > 0 THEN 'TRUE' ELSE 'FALSE' END AS is_refunded"),
                'orders.refund_status                      AS refund_status',
                DB::raw('0 AS refunded_items'),
                'refund_sum.last_refunded_at               AS last_refund_date',
                'refund_sum.first_refund_created_at        AS refunded_at',

                'orders.gateway_transaction_id             AS gateway_transaction_id',
                'orders.payment_gateway                    AS payment_gateway',

                'product_agg.products_concat               AS products_concat',
                'shipping_agg.courier_concat               AS courier_concat',
            ])
                ->orderBy('orders.created_at', 'desc');

            $paginated = $query->paginate($perPage);

            // FORMAT
            $stateNormalize = [
                'RAJISTHAN' => 'Rajasthan',
                'MADHYA PRADESH' => 'Madhya Pradesh',
                'MAADHYA PRADESH' => 'Madhya Pradesh',
                'HIMANCHAL PRADESH' => 'Himachal Pradesh',
                'WEST BANGAL' => 'West Bengal',
                'KARNATKA' => 'Karnataka',
                'KARANATAKA' => 'Karnataka',
                'CHHATISGRAH' => 'Chhattisgarh',
                'CHHATTSGARH' => 'Chhattisgarh',
                'GUJRAT' => 'Gujarat',
                'HARIYANA' => 'Haryana',
                'U.P' => 'Uttar Pradesh',
                'UP' => 'Uttar Pradesh',
            ];

            $gatewayDisplayMap = [
                'axispg'   => 'Freecharge',
                'razorpay' => 'Razorpay',
                'payu'     => 'PayU',
                'paytm'    => 'Paytm',
            ];

            /*
         * FLATTEN — one row per order line (item)
         */
            $flatData = [];

            $paginated->getCollection()->each(function ($row) use (
                $stateNormalize,
                $gatewayDisplayMap,
                &$flatData
            ) {
                $deliveryRaw = strtoupper(trim((string) ($row->delivery_state ?? '')));
                $billingRaw  = strtoupper(trim((string) ($row->billing_state ?? '')));

                $deliveryState = $stateNormalize[$deliveryRaw] ?? ($row->delivery_state ?? '');
                $billingState  = $stateNormalize[$billingRaw]  ?? ($row->billing_state ?? '');

                // Couriers keyed by order_line_id
                $couriersByLine = [];
                if (!empty($row->courier_concat)) {
                    foreach (explode('||', $row->courier_concat) as $courierStr) {
                        $parts = explode('::', $courierStr);
                        if (count($parts) >= 3) {
                            $lineId = (int) $parts[0];
                            $couriersByLine[$lineId][] = [
                                'courier_company'         => $parts[1] ?: null,
                                'courier_tracking_number' => $parts[2] ?: null,
                            ];
                        }
                    }
                }

                // Products (13 fields — gross amounts only)
                $products = [];
                if (!empty($row->products_concat)) {
                    foreach (explode('||', $row->products_concat) as $productStr) {
                        $parts = explode('::', $productStr);
                        if (count($parts) >= 13) {
                            $lineId          = (int) $parts[0];
                            $itemReferenceId = $parts[1];
                            $invoiceNumber   = $parts[11] !== '' ? $parts[11] : null;
                            $invoiceCreated  = $parts[12] !== '' ? $parts[12] : null;

                            $products[] = [
                                'order_line_id'      => $lineId,
                                'item_reference_id'  => $itemReferenceId,
                                'invoice_number'     => $invoiceNumber,
                                'invoice_date'       => $invoiceCreated
                                    ? Carbon::parse($invoiceCreated)->format('d-m-Y')
                                    : '',

                                'name'            => $parts[2],
                                'slug'            => $parts[3],
                                'product_code'    => $parts[4],
                                'hsn_code'        => $parts[5],
                                'quantity'        => (int) $parts[6],
                                'unit_price'      => round((float) $parts[7], 2),    // as stored
                                'line_total'      => round((float) $parts[8], 2),    // as stored
                                'tax_rate'        => round((float) $parts[9], 2),
                                'shipping_charge' => round((float) $parts[10], 2),   // as stored

                                'line_couriers'   => $couriersByLine[$lineId] ?? [],
                            ];
                        }
                    }
                }

                $paymentGatewayRaw     = strtolower(trim((string) ($row->payment_gateway ?? '')));
                $paymentGatewayDisplay = $gatewayDisplayMap[$paymentGatewayRaw] ?? ($row->payment_gateway ?? '');

                $orderHeader = [
                    'Order Reference'   => (string) ($row->order_reference ?? ''),
                    'Order Date'        => $row->order_date
                        ? Carbon::parse($row->order_date)->format('d-m-Y H:i')
                        : '',

                    'Customer Name'     => $row->customer_name ?? '',
                    'User Account Type' => $row->account_type ?? '',
                    'Customer Mobile'   => $row->customer_mobile ?? '',
                    'Customer Email'    => $row->customer_email ?? '',
                    'Customer GSTIN'    => $row->customer_gstin ?? 'URP',

                    'Gross Total'       => round((float) $row->gross_total, 2),
                    'Order Amount'      => round((float) $row->order_amount, 2),
                    'Net Order Amount'  => round((float) $row->net_order_amount, 2),

                    'Product Gross'     => round((float) $row->product_gross, 2),
                    'Shipping Gross'    => round((float) $row->shipping_gross, 2),

                    'Total List Price'  => round((float) $row->total_list_price, 2),
                    'Discount Amount'   => round((float) $row->discount_amount, 2),
                    'CV'                => round((float) $row->cv, 2),
                    'User Name'         => $row->user_name ?? '',

                    'Order Status'      => $row->order_status,
                    'Total Items'       => (int) $row->total_items,
                    'Delivery Status'   => $row->delivery_status ?? '',

                    'Confirmed At'      => $row->confirmed_at  ? Carbon::parse($row->confirmed_at)->format('d-m-Y H:i')  : '',
                    'Dispatched At'     => $row->dispatched_at ? Carbon::parse($row->dispatched_at)->format('d-m-Y H:i') : '',
                    'Shipping Date'     => $row->shipping_date ? Carbon::parse($row->shipping_date)->format('d-m-Y H:i') : '',
                    'Delivered At'      => $row->delivered_at  ? Carbon::parse($row->delivered_at)->format('d-m-Y H:i')  : '',
                    // 'Refunded At'       => $row->refunded_at   ? Carbon::parse($row->refunded_at)->format('d-m-Y H:i')   : '',
                    // 'Last Refund Date'  => $row->last_refund_date ? Carbon::parse($row->last_refund_date)->format('d-m-Y H:i') : '',

                    'Delivery Address'  => [
                        'address_id'      => $row->delivery_address_id,
                        'recipient_name'  => $row->delivery_recipient_name ?? '',
                        'contact_number'  => $row->delivery_contact_number ?? '',
                        'address_line_1'  => $row->delivery_address_line_1 ?? '',
                        'address_line_2'  => $row->delivery_address_line_2 ?? '',
                        'city'            => $row->delivery_city ?? '',
                        'post_office'     => $row->delivery_post_office ?? '',
                        'state'           => $deliveryState,
                        'postcode'        => $row->delivery_postcode ?? '',
                        'country'         => $row->delivery_country ?? '',
                    ],

                    'Billing Address'   => [
                        'address_id'      => $row->billing_address_id,
                        'recipient_name'  => $row->billing_recipient_name ?? '',
                        'contact_number'  => $row->billing_contact_number ?? '',
                        'address_line_1'  => $row->billing_address_line_1 ?? '',
                        'address_line_2'  => $row->billing_address_line_2 ?? '',
                        'city'            => $row->billing_city ?? '',
                        'post_office'     => $row->billing_post_office ?? '',
                        'state'           => $billingState,
                        'postcode'        => $row->billing_postcode ?? '',
                        'country'         => $row->billing_country ?? '',
                    ],

                    'Payment Gateway'         => $paymentGatewayDisplay,
                    'Transaction Gateway Id'  => $row->gateway_transaction_id ?? null,
                ];

                if (empty($products)) {
                    $flatData[] = array_merge($orderHeader, [
                        'Invoice Number'    => null,
                        'Invoice Date'      => '',
                        'order_line_id'     => null,
                        'item_reference_id' => null,
                        'Product Name'      => null,
                        'Product Slug'      => null,
                        'Product Code'      => null,
                        'HSN Code'          => null,
                        'Quantity'          => 0,
                        'Unit Price'        => 0,
                        'Line Total'        => 0,
                        'Tax Rate'          => 0,
                        'Line Shipping Charge' => 0,
                        'Line Couriers'     => [],
                    ]);
                    return;
                }

                foreach ($products as $p) {
                    $flatData[] = array_merge($orderHeader, [
                        'Invoice Number'    => $p['invoice_number'],
                        'Invoice Date'      => $p['invoice_date'],

                        'order_line_id'     => $p['order_line_id'],
                        'item_reference_id' => $p['item_reference_id'],

                        'Product Name'      => $p['name'],
                        'Product Slug'      => $p['slug'],
                        'Product Code'      => $p['product_code'],
                        'HSN Code'          => $p['hsn_code'],
                        'Quantity'          => $p['quantity'],
                        'Unit Price'        => $p['unit_price'],           // as stored
                        'Line Total'        => $p['line_total'],           // as stored
                        'Tax Rate'          => $p['tax_rate'],
                        'Line Shipping Charge' => $p['shipping_charge'],   // as stored
                        'Line Couriers'     => $p['line_couriers'],
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'data'    => $flatData,
                'meta'    => [
                    'total'        => $paginated->total(),
                    'per_page'     => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'rows_returned' => count($flatData),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Sales report failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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
                'ol.returned_quantity       as qty',
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
                // 'Refund Bill Id'         => $row->refund_bill_id,           // refunds.id
                'Original Bill Id'       => $row->original_bill_id,         // order_reference
                'Refund Amount'          => $row->refund_amount ?? $row->total_refund_amount,
                'Reason'                 => $row->return_reason,
                'Refund Date'            => $row->refund_date,
                'Distributor Id'         => $row->distributor_id,           // users.id (was User Name)
                'Customer Name'          => $row->customer_name,
                'Contact Number'         => $row->contact_number,

                // Extra useful columns
                // 'Ignite Order No'        => $row->ignite_order_no,
                // 'Order Date'             => $row->order_date,
                // 'Return Id'              => $row->return_id,
                // 'Return Type'            => $row->return_type,
                // 'Return Status'          => $row->return_status,
                // 'Return Resolution'      => $row->return_resolution,
                // 'Return Tracking Id'     => $row->return_tracking_id,
                // 'Return Courier'         => $row->return_courier,
                // 'Return Received At'     => $row->return_received_at,
                // 'Return Completed At'    => $row->return_completed_at,

                'Item Name'              => $row->item_name,
                'SKU'                    => $row->sku,
                // 'QTY'                    => $row->qty,
                // 'Unit Price'             => $row->unit_price,
                // 'Line Total'             => $row->line_total,

                // 'Refund Subtotal'        => $row->refund_subtotal,
                // 'Refund Tax'             => $row->refund_tax,
                // 'Refund Shipping'        => $row->refund_shipping,
                // 'Refund Gateway Charges' => $row->refund_gateway_charges,
                // 'Refund Extra Deductions' => $row->refund_extra_deductions,
                // 'Total Refund Amount'    => $row->total_refund_amount,
                // 'Refund Adjusted Amount' => $row->refund_adjusted_amount,
                // 'Refund Status'          => $row->refund_status,
                // 'Refund Method'          => $row->refund_method,
                // 'Refund Gateway Ref'     => $row->refund_gateway_reference,
                // 'Refund Failure Reason'  => $row->refund_failure_reason,
                // 'Refund Notes'           => $row->refund_notes,
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
    public function returnArticlereport(Request $request)
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
                'ol.returned_quantity       as qty',
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
                // 'Refund Bill Id'         => $row->refund_bill_id,           // refunds.id
                'Original Bill Id'       => $row->original_bill_id,         // order_reference
                'Refund Amount'          => $row->refund_amount ?? $row->total_refund_amount,
                'Reason'                 => $row->return_reason,
                'Refund Date'            => $row->refund_date,
                'Distributor Id'         => $row->distributor_id,           // users.id (was User Name)
                'Customer Name'          => $row->customer_name,
                'Contact Number'         => $row->contact_number,

                // Extra useful columns
                // 'Ignite Order No'        => $row->ignite_order_no,
                // 'Order Date'             => $row->order_date,
                // 'Return Id'              => $row->return_id,
                // 'Return Type'            => $row->return_type,
                // 'Return Status'          => $row->return_status,
                // 'Return Resolution'      => $row->return_resolution,
                // 'Return Tracking Id'     => $row->return_tracking_id,
                // 'Return Courier'         => $row->return_courier,
                // 'Return Received At'     => $row->return_received_at,
                // 'Return Completed At'    => $row->return_completed_at,

                'Item Name'              => $row->item_name,
                'SKU'                    => $row->sku,
                'QTY'                    => $row->qty,
                // 'Unit Price'             => $row->unit_price,
                // 'Line Total'             => $row->line_total,

                // 'Refund Subtotal'        => $row->refund_subtotal,
                // 'Refund Tax'             => $row->refund_tax,
                // 'Refund Shipping'        => $row->refund_shipping,
                // 'Refund Gateway Charges' => $row->refund_gateway_charges,
                // 'Refund Extra Deductions' => $row->refund_extra_deductions,
                // 'Total Refund Amount'    => $row->total_refund_amount,
                // 'Refund Adjusted Amount' => $row->refund_adjusted_amount,
                // 'Refund Status'          => $row->refund_status,
                // 'Refund Method'          => $row->refund_method,
                // 'Refund Gateway Ref'     => $row->refund_gateway_reference,
                // 'Refund Failure Reason'  => $row->refund_failure_reason,
                // 'Refund Notes'           => $row->refund_notes,
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
    
    public function financeSalesReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|in:confirmed,dispatched,shipped,delivered,partial_dispatched,partial_shipped,partial_delivered',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $allowedStatuses = $request->filled('status')
                ? [$request->status]
                : [
                    'dispatched',
                    'shipped',
                    'delivered',
                    'undelivered',
                    'partial_dispatched',
                    'partial_shipped',
                    'partial_delivered',
                ];

            $perPage = (int) $request->input('per_page', 100);

            // Refund aggregate
            $refundSub = DB::table('refunds')
                ->select('order_id')
                ->selectRaw('SUM(amount) AS total_refunded')
                ->selectRaw('MAX(completed_at) AS last_refunded_at')
                ->selectRaw('MAX(created_at) AS first_refund_created_at')
                ->where('status', 'completed')
                ->groupBy('order_id');

            // Customer GSTIN
            $gstinSub = DB::table('distributor_profiles')
                ->select('user_id')
                ->selectRaw('MAX(gst_in) AS gst_in')
                ->whereNotNull('gst_in')
                ->groupBy('user_id');

            /*
         * INVOICE AGGREGATE — KEYED BY order_line_id (per item)
         */
            $invoiceSub = DB::table('invoices')
                ->select('order_line_id')
                ->selectRaw('MIN(invoice_number) AS invoice_number')
                ->selectRaw('MIN(created_at) AS invoice_created_at')
                ->whereNotNull('order_line_id')
                ->groupBy('order_line_id');

            /*
         * LINE AGGREGATE
         * Used ONLY for Order-level totals / meta context
         */
            $taxRate = 'COALESCE(order_lines.gst_rate, tax_categories.rate, 0)';

            $productTax = "
            (
                COALESCE(order_lines.line_total, 0)
                -
                (
                    COALESCE(order_lines.line_total, 0)
                    / (1 + ($taxRate / 100))
                )
            )
        ";

            $shippingTax = "
            (
                COALESCE(order_lines.shipping_charge, 0)
                -
                (
                    COALESCE(order_lines.shipping_charge, 0)
                    / (1 + ($taxRate / 100))
                )
            )
        ";

            $productWithoutTax = "
            (
                COALESCE(order_lines.line_total, 0)
                / (1 + ($taxRate / 100))
            )
        ";

            $shippingWithoutTax = "
            (
                COALESCE(order_lines.shipping_charge, 0)
                / (1 + ($taxRate / 100))
            )
        ";

            $lineAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->leftJoin('addresses as delivery_addr', 'delivery_addr.id', '=', 'orders.delivery_address_id')
                ->leftJoin('addresses as billing_addr', 'billing_addr.id', '=', 'orders.billing_address_id')

                ->select('order_lines.order_id')

                ->selectRaw('SUM(order_lines.quantity) AS total_items')
                ->selectRaw('SUM(order_lines.quantity * COALESCE(products.commission_value, 0)) AS total_cv')
                ->selectRaw('MAX(order_lines.delivery_status) AS item_delivery_status')
                ->selectRaw('MAX(order_lines.shipped_at) AS max_shipped_at')
                ->selectRaw('MAX(order_lines.dispatched_at) AS max_dispatched_at')
                ->selectRaw('MAX(order_lines.delivered_at) AS max_delivered_at')
                ->selectRaw('MIN(order_lines.created_at) AS min_line_created_at')
                ->selectRaw('MAX(delivery_addr.state) AS delivery_state')
                ->selectRaw('MAX(billing_addr.billing_state) AS billing_state')
                ->selectRaw("
                CASE
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) = COUNT(*) THEN 'delivered'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) > 0 THEN 'partial_delivered'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'shipped' THEN 1 ELSE 0 END) > 0
                        AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) > 0
                        THEN 'partial_shipped'
                    WHEN SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) = 0 THEN 'shipped'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0
                        AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('dispatched','shipped','delivered') THEN 1 ELSE 0 END) > 0
                        THEN 'partial_dispatched'
                    WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0 THEN 'dispatched'
                    ELSE 'confirmed'
                END AS overall_delivery_status
            ")
                ->groupBy('order_lines.order_id');

            /*
         * PRODUCT DETAILS — joined to invoice per line.
         *
         * Layout (15 fields — added category_name):
         *  0  order_line_id
         *  1  item_reference_id
         *  2  name
         *  3  slug
         *  4  hsn_code
         *  5  product_code
         *  6  quantity
         *  7  unit_price
         *  8  line_total
         *  9  gst_rate
         *  10 shipping_charge
         *  11 invoice_number
         *  12 invoice_created_at
         *  13 service_charge
         *  14 category_name
         */
            $productAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id') // JOIN CATEGORIES
                ->leftJoinSub($invoiceSub, 'invoice_data', function ($join) {
                    $join->on('invoice_data.order_line_id', '=', 'order_lines.id');
                })

                ->select('order_lines.order_id')

                ->selectRaw("
                GROUP_CONCAT(
                    CONCAT_WS('::',
                        COALESCE(order_lines.id, 0),
                        COALESCE(order_lines.item_reference_id, ''),
                        COALESCE(products.name, ''),
                        COALESCE(products.slug, ''),
                        COALESCE(products.hsn_code, ''),
                        COALESCE(products.product_code, ''),
                        COALESCE(order_lines.quantity, 0),
                        COALESCE(order_lines.unit_price, 0),
                        COALESCE(order_lines.line_total, 0),
                        COALESCE(order_lines.gst_rate, tax_categories.rate, 0),
                        COALESCE(order_lines.shipping_charge, 0),
                        COALESCE(invoice_data.invoice_number, ''),
                        COALESCE(invoice_data.invoice_created_at, ''),
                        COALESCE(products.service_charge, 0),
                        COALESCE(categories.title, '')
                    )
                    ORDER BY products.name SEPARATOR '||'
                ) AS products_concat
            ")

                ->groupBy('order_lines.order_id');

            /*
         * COURIER AGGREGATE (per line)
         */
            $shippingAggSub = DB::table('order_shipping_details as osd')
                ->join('order_lines as ol', 'ol.id', '=', 'osd.order_line_id')
                ->select('ol.order_id')
                ->selectRaw("
                GROUP_CONCAT(
                    CONCAT_WS('::',
                        COALESCE(osd.order_line_id, 0),
                        COALESCE(osd.courier_company, ''),
                        COALESCE(osd.courier_tracking_number, '')
                    )
                    ORDER BY osd.id SEPARATOR '||'
                ) AS courier_concat
            ")
                ->groupBy('ol.order_id');

            /*
         * BASE QUERY
         */
            $query = Order::query()
                ->join('users', 'users.id', '=', 'orders.user_id')

                ->leftJoinSub($refundSub, 'refund_sum', function ($join) {
                    $join->on('refund_sum.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($gstinSub, 'gstin_data', function ($join) {
                    $join->on('gstin_data.user_id', '=', 'users.id');
                })
                ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
                    $join->on('line_agg.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($productAggSub, 'product_agg', function ($join) {
                    $join->on('product_agg.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($shippingAggSub, 'shipping_agg', function ($join) {
                    $join->on('shipping_agg.order_id', '=', 'orders.id');
                })

                ->whereIn('line_agg.overall_delivery_status', $allowedStatuses)
                ->whereNull('orders.deleted_at');

            if ($request->filled('from')) {
                $query->where('orders.created_at', '>=', $request->from . ' 00:00:00');
            }

            if ($request->filled('to')) {
                $query->where('orders.created_at', '<=', $request->to . ' 23:59:59');
            }

            /*
         * SELECT — Order level fields
         */
            $query->select([
                'orders.order_reference AS order_reference',
                'orders.created_at AS order_date',

                'users.full_name AS customer_name',
                'users.account_type AS account_type',
                'users.phone AS customer_mobile',
                'users.email AS customer_email',

                'orders.total_payable AS order_amount',

                DB::raw('COALESCE(refund_sum.total_refunded, 0) AS refunded_amount'),
                DB::raw('(orders.total_payable - COALESCE(refund_sum.total_refunded, 0)) AS net_order_amount'),

                'orders.status AS order_status',
                DB::raw('COALESCE(line_agg.total_items, 0) AS total_items'),

                'orders.coupon_discount AS discount_amount',
                DB::raw('COALESCE(line_agg.total_cv, 0) AS cv'),
                'users.distributor_id AS user_name',
                'gstin_data.gst_in AS customer_gstin',

                'line_agg.max_shipped_at AS shipping_date',
                'line_agg.item_delivery_status AS item_delivery_status',
                'line_agg.min_line_created_at AS confirmed_at',
                'line_agg.max_dispatched_at AS dispatched_at',
                'line_agg.max_delivered_at AS delivered_at',

                'line_agg.billing_state',
                'line_agg.delivery_state',
                'line_agg.overall_delivery_status AS delivery_status',

                'orders.payment_gateway AS payment_gateway',
                'orders.gateway_transaction_id AS gateway_transaction_id',

                'product_agg.products_concat AS products_concat',
                'shipping_agg.courier_concat AS courier_concat',
            ]);

            $query->orderBy('orders.created_at', 'desc');

            $paginated = $query->paginate($perPage);

            /*
         * STATE NORMALIZATION
         */
            $stateNormalize = [
                'RAJISTHAN' => 'Rajasthan',
                'MAADHYA PRADESH' => 'Madhya Pradesh',
                'HIMANCHAL PRADESH' => 'Himachal Pradesh',
                'WEST BANGAL' => 'West Bengal',
                'KARNATKA' => 'Karnataka',
                'KARANATAKA' => 'Karnataka',
                'CHHATISGRAH' => 'Chhattisgarh',
                'CHHATTSGARH' => 'Chhattisgarh',
                'GUJRAT' => 'Gujarat',
                'HARIYANA' => 'Haryana',
                'U.P' => 'Uttar Pradesh',
                'UP' => 'Uttar Pradesh',
            ];

            $gatewayDisplayMap = [
                'axispg' => 'Freecharge',
                'razorpay' => 'Razorpay',
                'payu' => 'PayU',
                'paytm' => 'Paytm',
            ];

            // Supplier State from Config
            $supplierState = strtolower(trim(config('app.supplier_state', 'Punjab')));

            /*
         * FORMAT RESPONSE — FLATTENED PER ITEM
         */
            $flatData = [];

            $paginated->getCollection()->each(function ($row) use (
                $stateNormalize,
                $gatewayDisplayMap,
                $supplierState,
                &$flatData
            ) {
                $billingRaw = strtoupper(trim((string) ($row->billing_state ?? '')));
                $deliveryRaw = strtoupper(trim((string) ($row->delivery_state ?? '')));

                $billingState = $stateNormalize[$billingRaw] ?? ($row->billing_state ?? '');
                $deliveryState = $stateNormalize[$deliveryRaw] ?? ($row->delivery_state ?? '');

                // Normalize delivery state for comparison
                $deliveryStateLower = strtolower(trim($deliveryState));

                // Couriers keyed by order_line_id
                $couriersByLine = [];

                if (!empty($row->courier_concat)) {
                    foreach (explode('||', $row->courier_concat) as $courierStr) {
                        $parts = explode('::', $courierStr);

                        if (count($parts) >= 3) {
                            $lineId = (int) $parts[0];

                            $couriersByLine[$lineId][] = [
                                'courier_company' => $parts[1] ?: null,
                                'courier_tracking_number' => $parts[2] ?: null,
                            ];
                        }
                    }
                }

                // Products
                $products = [];

                if (!empty($row->products_concat)) {
                    foreach (explode('||', $row->products_concat) as $productStr) {
                        $parts = explode('::', $productStr);

                        if (count($parts) >= 15) {
                            $lineId = (int) $parts[0];
                            $itemReferenceId = $parts[1];

                            $qty = (int) $parts[6];

                            $unitPrice = (float) $parts[7];
                            $lineTotal = (float) $parts[8];
                            $taxRate = (float) $parts[9];
                            $shippingCharge = (float) $parts[10];

                            $invoiceNumber = $parts[11] !== '' ? $parts[11] : null;
                            $invoiceCreatedAt = $parts[12] !== '' ? $parts[12] : null;
                            $serviceChargePerUnit = (float) $parts[13];
                            $categoryName = $parts[14] !== '' ? $parts[14] : null;

                            $divisor = 1 + ($taxRate / 100);

                            // 1. GST Extraction for Product
                            $lineTotalWithoutGst = $divisor > 0
                                ? $lineTotal / $divisor
                                : $lineTotal;

                            $unitPriceWithoutGst = $qty > 0
                                ? $lineTotalWithoutGst / $qty
                                : 0;

                            $productGst = $lineTotal - $lineTotalWithoutGst;

                            // 2. GST Extraction for Shipping
                            $shippingWithoutGst = $divisor > 0
                                ? $shippingCharge / $divisor
                                : $shippingCharge;

                            $shippingGst = $shippingCharge - $shippingWithoutGst;

                            // 3. Service Charge Calculation (per item)
                            $totalServiceCharge = $serviceChargePerUnit * $qty;

                            // 4. Total Item Amount (Base + Shipping + Service)
                            $totalItemAmount = $lineTotal + $shippingCharge + $totalServiceCharge;

                            $products[] = [
                                'order_line_id' => $lineId,
                                'item_reference_id' => $itemReferenceId,
                                'invoice_number' => $invoiceNumber,
                                'invoice_date' => $invoiceCreatedAt
                                    ? Carbon::parse($invoiceCreatedAt)->format('d-m-Y')
                                    : '',

                                'name' => $parts[2],
                                'slug' => $parts[3],
                                'hsn_code' => $parts[4],
                                'product_code' => $parts[5],
                                'category_name' => $categoryName,

                                'quantity' => $qty,

                                // Extracted values for this specific item
                                'unit_price_without_gst' => round($unitPriceWithoutGst, 2),
                                'line_total_without_gst' => round($lineTotalWithoutGst, 2),
                                'shipping_charge_without_gst' => round($shippingWithoutGst, 2),

                                'service_charge_per_unit' => round($serviceChargePerUnit, 2),
                                'total_service_charge' => round($totalServiceCharge, 2),

                                'total_item_amount' => round($totalItemAmount, 2),

                                'tax_rate' => round($taxRate, 2),
                                'gst_amount' => round($productGst, 2),
                                'shipping_gst' => round($shippingGst, 2),

                                'line_couriers' => $couriersByLine[$lineId] ?? [],
                            ];
                        }
                    }
                }

                $paymentGatewayRaw = strtolower(trim((string) ($row->payment_gateway ?? '')));
                $paymentGatewayDisplay = $gatewayDisplayMap[$paymentGatewayRaw]
                    ?? ($row->payment_gateway ?? '');

                /*
             * ORDER-LEVEL fields (Header)
             */
                $orderHeader = [
                    'order_reference' => (string) ($row->order_reference ?? ''),

                    'customer_name' => $row->customer_name ?? '',
                    'account_type' => $row->account_type ?? '',
                    'customer_mobile' => $row->customer_mobile ?? '',
                    'customer_email' => $row->customer_email ?? '',
                    'customer_gstin' => $row->customer_gstin ?? 'URP',

                    'billing_state' => $billingState,
                    'delivery_state' => $deliveryState,

                    'discount_amount' => round((float) $row->discount_amount, 2),

                    'order_status' => $row->order_status,
                    'item_delivery_status' => $row->item_delivery_status,

                    'cv' => round((float) $row->cv, 2),
                    'user_name' => $row->user_name ?? '',

                    'order_date' => $row->order_date
                        ? Carbon::parse($row->order_date)->format('d-m-Y H:i')
                        : '',
                    'confirmed_at' => $row->confirmed_at
                        ? Carbon::parse($row->confirmed_at)->format('d-m-Y H:i')
                        : '',
                    'dispatched_at' => $row->dispatched_at
                        ? Carbon::parse($row->dispatched_at)->format('d-m-Y H:i')
                        : '',
                    'shipping_date' => $row->shipping_date
                        ? Carbon::parse($row->shipping_date)->format('d-m-Y H:i')
                        : '',
                    'delivered_at' => $row->delivered_at
                        ? Carbon::parse($row->delivered_at)->format('d-m-Y H:i')
                        : '',

                    'payment_gateway' => $paymentGatewayDisplay,
                    'gateway_transaction_id' => $row->gateway_transaction_id ?? null,
                ];

                if (empty($products)) {
                    $flatData[] = array_merge($orderHeader, [
                        'total_items' => 0,
                        'invoice_number' => '',
                        'invoice_date' => '',
                        'order_line_id' => null,
                        'item_reference_id' => null,
                        'product_name' => null,
                        'product_slug' => null,
                        'hsn_code' => null,
                        'product_code' => null,
                        'category_name' => null,
                        'quantity' => 0,
                        'product_subtotal' => 0,
                        'shipping_charge_without_gst' => 0,
                        'product_total_shipping_charge_without_gst' => 0,
                        'product_gst' => 0,
                        'shipping_gst' => 0,
                        'total_gst' => 0,
                        'tax_breakdown' => ['cgst' => 0, 'sgst' => 0, 'igst' => 0],
                        'unit_price_without_gst' => 0,
                        'line_total_without_gst' => 0,
                        'line_shipping_charge_without_gst' => 0,
                        'service_charge_per_unit' => 0,
                        'total_service_charge' => 0,
                        'total_item_amount' => 0,
                        'line_tax_rate' => 0,
                        'line_gst_amount' => 0,
                        'line_shipping_gst' => 0,
                        'line_couriers' => [],
                    ]);
                    return;
                }

                foreach ($products as $p) {
                    // Compare Supplier State with Delivery State
                    $isIntraState = ($supplierState === $deliveryStateLower && !empty($deliveryStateLower));

                    $totalItemGst = $p['gst_amount'] + $p['shipping_gst'];

                    $itemCgst = $isIntraState ? round($totalItemGst / 2, 2) : 0;
                    $itemSgst = $isIntraState ? round($totalItemGst / 2, 2) : 0;
                    $itemIgst = !$isIntraState ? round($totalItemGst, 2) : 0;

                    $flatData[] = array_merge($orderHeader, [
                        'total_items' => $p['quantity'], // PER ITEM QUANTITY

                        'invoice_number' => $p['invoice_number'] ?? '',
                        'invoice_date'   => $p['invoice_date'] ?? '',

                        'order_line_id' => $p['order_line_id'],
                        'item_reference_id' => $p['item_reference_id'],
                        'product_name' => $p['name'],
                        'product_slug' => $p['slug'],
                        'hsn_code' => $p['hsn_code'],
                        'product_code' => $p['product_code'],
                        'category_name' => $p['category_name'],
                        'quantity' => $p['quantity'],

                        // Per-item values mapped to the original keys
                        'product_subtotal' => $p['line_total_without_gst'],
                        'shipping_charge_without_gst' => $p['shipping_charge_without_gst'],
                        'product_total_shipping_charge_without_gst' => round($p['line_total_without_gst'] + $p['shipping_charge_without_gst'], 2),

                        'product_gst' => $p['gst_amount'],
                        'shipping_gst' => $p['shipping_gst'],
                        'total_gst' => round($totalItemGst, 2),

                        'tax_breakdown' => [
                            'cgst' => $itemCgst,
                            'sgst' => $itemSgst,
                            'igst' => $itemIgst,
                        ],

                        // Per-item sub-values
                        'unit_price_without_gst' => $p['unit_price_without_gst'],
                        'line_total_without_gst' => $p['line_total_without_gst'],
                        'line_shipping_charge_without_gst' => $p['shipping_charge_without_gst'],

                        'service_charge_per_unit' => $p['service_charge_per_unit'],
                        'total_service_charge' => $p['total_service_charge'],
                        'total_item_amount' => $p['total_item_amount'],

                        'line_tax_rate' => $p['tax_rate'],
                        'line_gst_amount' => $p['gst_amount'],
                        'line_shipping_gst' => $p['shipping_gst'],
                        'line_couriers' => $p['line_couriers'],
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'data' => $flatData,
                'meta' => [
                    'total' => $paginated->total(),
                    'per_page' => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'rows_returned' => count($flatData),
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

    public function hsnSummaryReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|in:confirmed,dispatched,shipped,delivered,partial_dispatched,partial_shipped,partial_delivered',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'registration_type' => 'nullable|in:registered,unregistered,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $allowedStatuses = $request->filled('status')
                ? [$request->status]
                : [
                    'dispatched', 'shipped', 'delivered',
                    'partial_dispatched', 'partial_shipped', 'partial_delivered',
                ];

            /*
            * GSTIN SUBQUERY
            * gst_in jo NULL na ho, empty na ho, aur 'URP' na ho — sirf wahi Registered hai.
            * Hum yahan normalized value nikalenge taaki baad mein CASE simple rahe.
            */
            $gstinSub = DB::table('distributor_profiles')
                ->select('user_id')
                ->selectRaw("
                    MAX(
                        CASE
                            WHEN gst_in IS NOT NULL
                                AND TRIM(gst_in) <> ''
                                AND UPPER(TRIM(gst_in)) <> 'URP'
                            THEN TRIM(gst_in)
                            ELSE NULL
                        END
                    ) AS gst_in
                ")
                ->groupBy('user_id');

            /*
            * LINE AGG SUBQUERY (order-level status + delivery state)
            */
            $lineAggSub = DB::table('order_lines')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->leftJoin('addresses as delivery_addr', 'delivery_addr.id', '=', 'orders.delivery_address_id')
                ->select('order_lines.order_id')
                ->selectRaw('MAX(delivery_addr.state) AS delivery_state')
                ->selectRaw("
                    CASE
                        WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) = COUNT(*) THEN 'delivered'
                        WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) > 0 THEN 'partial_delivered'
                        WHEN SUM(CASE WHEN order_lines.delivery_status = 'shipped' THEN 1 ELSE 0 END) > 0
                            AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) > 0
                            THEN 'partial_shipped'
                        WHEN SUM(CASE WHEN order_lines.delivery_status NOT IN ('shipped','delivered') THEN 1 ELSE 0 END) = 0 THEN 'shipped'
                        WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0
                            AND SUM(CASE WHEN order_lines.delivery_status NOT IN ('dispatched','shipped','delivered') THEN 1 ELSE 0 END) > 0
                            THEN 'partial_dispatched'
                        WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0 THEN 'dispatched'
                        ELSE 'confirmed'
                    END AS overall_delivery_status
                ")
                ->groupBy('order_lines.order_id');

            $taxRate = 'COALESCE(order_lines.gst_rate, tax_categories.rate, 0)';

            /*
            * MAIN HSN QUERY
            * Grouping key: hsn + item_name + rate + registration_type + is_intra_state
            * Isse CGST/SGST vs IGST ka exact split milega (multi-state orders bhi handle honge).
            */
            $supplierState = strtolower(trim(config('app.supplier_state', 'Punjab')));

            $query = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->join('users', 'users.id', '=', 'orders.user_id')
                ->leftJoinSub($gstinSub, 'gstin_data', function ($join) {
                    $join->on('gstin_data.user_id', '=', 'users.id');
                })
                ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
                    $join->on('line_agg.order_id', '=', 'orders.id');
                })
                ->whereIn('line_agg.overall_delivery_status', $allowedStatuses)
                ->whereNull('orders.deleted_at');

            if ($request->filled('from')) {
                $query->where('orders.created_at', '>=', $request->from . ' 00:00:00');
            }
            if ($request->filled('to')) {
                $query->where('orders.created_at', '<=', $request->to . ' 23:59:59');
            }

            // Registration type detection — URP / NULL / empty => unregistered
            $registrationCase = "
                CASE
                    WHEN gstin_data.gst_in IS NOT NULL
                        AND TRIM(gstin_data.gst_in) <> ''
                        AND UPPER(TRIM(gstin_data.gst_in)) <> 'URP'
                    THEN 'registered'
                    ELSE 'unregistered'
                END
            ";

            // Intra-state detection based on supplier vs delivery state
            $intraCase = "
                CASE
                    WHEN LOWER(TRIM(COALESCE(line_agg.delivery_state, ''))) = " . DB::getPdo()->quote($supplierState) . "
                        AND TRIM(COALESCE(line_agg.delivery_state, '')) <> ''
                    THEN 1
                    ELSE 0
                END
            ";

            $registrationFilter = $request->input('registration_type', 'all');
            if ($registrationFilter === 'registered') {
                $query->whereRaw("$registrationCase = 'registered'");
            } elseif ($registrationFilter === 'unregistered') {
                $query->whereRaw("$registrationCase = 'unregistered'");
            }

            $rows = $query
                ->select([
                    'products.hsn_code AS hsn',
                    'products.name AS item_name',
                    DB::raw("$taxRate AS tax_rate"),
                    DB::raw("$registrationCase AS registration_type"),
                    DB::raw("$intraCase AS is_intra_state"),
                ])
                ->selectRaw('SUM(order_lines.quantity) AS total_quantity')
                ->selectRaw("
                    SUM(
                        COALESCE(order_lines.line_total, 0)
                        + COALESCE(order_lines.shipping_charge, 0)
                        + (COALESCE(products.service_charge, 0) * COALESCE(order_lines.quantity, 0))
                    ) AS total_value
                ")
                ->selectRaw("
                    SUM(
                        COALESCE(order_lines.line_total, 0) / (1 + ($taxRate / 100))
                    ) AS taxable_product_value
                ")
                ->selectRaw("
                    SUM(
                        COALESCE(order_lines.shipping_charge, 0) / (1 + ($taxRate / 100))
                    ) AS taxable_shipping_value
                ")
                ->selectRaw("
                    SUM(
                        COALESCE(order_lines.line_total, 0)
                        - (COALESCE(order_lines.line_total, 0) / (1 + ($taxRate / 100)))
                    ) AS product_gst
                ")
                ->selectRaw("
                    SUM(
                        COALESCE(order_lines.shipping_charge, 0)
                        - (COALESCE(order_lines.shipping_charge, 0) / (1 + ($taxRate / 100)))
                    ) AS shipping_gst
                ")
                ->groupByRaw("
                    products.hsn_code,
                    products.name,
                    $taxRate,
                    $registrationCase,
                    $intraCase
                ")
                ->orderBy('registration_type')
                ->orderBy('products.hsn_code')
                ->get();

            /*
            * PHP-side merge: agar same hsn + rate + registration_type ke
            * do rows hain (ek intra, ek inter) to unhe ek row mein merge karenge.
            */
            $registered = [];
            $unregistered = [];

            foreach ($rows as $row) {
                $rate = (float) $row->tax_rate;
                $isIntra = (int) $row->is_intra_state === 1;

                $productGst = (float) $row->product_gst;
                $shippingGst = (float) $row->shipping_gst;
                $totalGst = $productGst + $shippingGst;

                $taxableValue = (float) $row->taxable_product_value + (float) $row->taxable_shipping_value;

                $igst = $isIntra ? 0 : round($totalGst, 2);
                $cgst = $isIntra ? round($totalGst / 2, 2) : 0;
                $sgst = $isIntra ? round($totalGst / 2, 2) : 0;

                $key = $row->hsn . '|' . $rate . '|' . $row->registration_type;

                $target = &$registered;
                if ($row->registration_type === 'unregistered') {
                    $target = &$unregistered;
                }

                if (!isset($target[$key])) {
                    $target[$key] = [
                        'item_name' => $row->item_name,
                        'hsn' => $row->hsn,
                        'uqc' => 'NOS-NUMBERS',
                        'total_quantity' => 0,
                        'total_value' => 0,
                        'rate' => round($rate, 2),
                        'taxable_value' => 0,
                        'integrated_tax_amount' => 0,
                        'central_tax_amount' => 0,
                        'state_ut_tax_amount' => 0,
                        'cess_amount' => 0,
                        'registration_type' => $row->registration_type,
                    ];
                }

                $target[$key]['total_quantity'] += (int) $row->total_quantity;
                $target[$key]['total_value'] += round((float) $row->total_value, 2);
                $target[$key]['taxable_value'] += round($taxableValue, 2);
                $target[$key]['integrated_tax_amount'] += $igst;
                $target[$key]['central_tax_amount'] += $cgst;
                $target[$key]['state_ut_tax_amount'] += $sgst;

                unset($target);
            }

            // Final rounding
            foreach ([&$registered, &$unregistered] as &$bucket) {
                foreach ($bucket as &$item) {
                    $item['total_value'] = round($item['total_value'], 2);
                    $item['taxable_value'] = round($item['taxable_value'], 2);
                    $item['integrated_tax_amount'] = round($item['integrated_tax_amount'], 2);
                    $item['central_tax_amount'] = round($item['central_tax_amount'], 2);
                    $item['state_ut_tax_amount'] = round($item['state_ut_tax_amount'], 2);
                    unset($item['registration_type']);
                }
                unset($item);
                $bucket = array_values($bucket);
            }
            unset($bucket);

            return response()->json([
                'success' => true,
                'data' => [
                    'registered' => $registered,
                    'unregistered' => $unregistered,
                ],
                'meta' => [
                    'from' => $request->from,
                    'to' => $request->to,
                    'statuses' => $allowedStatuses,
                    'supplier_state' => config('app.supplier_state', 'Punjab'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('HSN summary report failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

}