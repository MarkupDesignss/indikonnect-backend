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

            // Invoice subquery (invoice number + invoice date)
            $invoiceSub = DB::table('invoices')
                ->select('order_id')
                ->selectRaw('MIN(invoice_number) AS invoice_number')
                ->selectRaw('MIN(created_at) AS invoice_created_at')
                ->groupBy('order_id');

            /*
         * LINE AGGREGATE SUBQUERY
         *
         * GST extraction identical to financeSalesReport.
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

                // Gross (GST-inclusive) — NOT excluded
                ->selectRaw('SUM(COALESCE(order_lines.line_total, 0)) AS product_gross')
                ->selectRaw('SUM(COALESCE(order_lines.shipping_charge, 0)) AS shipping_charge_gross')

                // Without GST versions (for the split)
                ->selectRaw("SUM($productWithoutTax) AS product_subtotal")
                ->selectRaw("SUM($shippingWithoutTax) AS shipping_without_gst")

                // GST components
                ->selectRaw("SUM($productTax) AS product_gst")
                ->selectRaw("SUM($shippingTax) AS shipping_gst")
                ->selectRaw("SUM($productTax + $shippingTax) AS total_gst")

                // Intra-state GST
                ->selectRaw("
                SUM(
                    CASE
                        WHEN billing_addr.state IS NOT NULL
                        AND delivery_addr.state IS NOT NULL
                        AND TRIM(billing_addr.state) != ''
                        AND TRIM(delivery_addr.state) != ''
                        AND UPPER(TRIM(billing_addr.state)) = UPPER(TRIM(delivery_addr.state))
                        THEN $productTax + $shippingTax
                        ELSE 0
                    END
                ) AS intra_state_tax
            ")

                // Inter-state GST
                ->selectRaw("
                SUM(
                    CASE
                        WHEN billing_addr.state IS NULL
                            OR delivery_addr.state IS NULL
                            OR TRIM(billing_addr.state) = ''
                            OR TRIM(delivery_addr.state) = ''
                            OR UPPER(TRIM(billing_addr.state)) != UPPER(TRIM(delivery_addr.state))
                        THEN $productTax + $shippingTax
                        ELSE 0
                    END
                ) AS inter_state_tax
            ")

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
         * PRODUCTS SUBQUERY
         *
         * Now includes BOTH:
         *   - order_lines.item_reference  -> per-line item reference
         *   - orders.order_reference      -> order reference (via join to orders)
         *
         * Layout (10 fields):
         *  0 name
         *  1 slug
         *  2 product_code
         *  3 hsn_code
         *  4 quantity
         *  5 unit_price       (GST-inclusive)
         *  6 line_total       (GST-inclusive)
         *  7 gst_rate
         *  8 shipping_charge  (GST-inclusive)
         *  9 item_reference   (NEW)
         */
            $productAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->select('order_lines.order_id')
                ->selectRaw("
                GROUP_CONCAT(
                    CONCAT_WS('::',
                        COALESCE(products.name, ''),
                        COALESCE(products.slug, ''),
                        COALESCE(products.product_code, ''),
                        COALESCE(products.hsn_code, ''),
                        COALESCE(order_lines.quantity, 0),
                        COALESCE(order_lines.unit_price, 0),
                        COALESCE(order_lines.line_total, 0),
                        COALESCE(order_lines.gst_rate, tax_categories.rate, 0),
                        COALESCE(order_lines.shipping_charge, 0),
                        COALESCE(order_lines.item_reference_id, '')
                    )
                    ORDER BY products.name SEPARATOR '||'
                ) AS products_concat
            ")
                ->groupBy('order_lines.order_id');

            // LINE SHIPPING DETAILS SUBQUERY
            $shippingAggSub = DB::table('order_shipping_details as osd')
                ->join('order_lines as ol', 'ol.id', '=', 'osd.order_line_id')
                ->select('ol.order_id')
                ->selectRaw("
                GROUP_CONCAT(
                    DISTINCT CONCAT_WS('::',
                        COALESCE(osd.courier_company, ''),
                        COALESCE(osd.courier_tracking_number, '')
                    )
                    ORDER BY osd.id SEPARATOR '||'
                ) AS courier_concat
            ")
                ->groupBy('ol.order_id');

            // BASE QUERY
            $query = Order::query()
                ->join('users', 'users.id', '=', 'orders.user_id')
                ->leftJoinSub($refundSub, 'refund_sum', function ($join) {
                    $join->on('refund_sum.order_id', '=', 'orders.id');
                })
                ->leftJoinSub($gstinSub, 'gstin_data', function ($join) {
                    $join->on('gstin_data.user_id', '=', 'users.id');
                })
                ->leftJoinSub($invoiceSub, 'invoice_data', function ($join) {
                    $join->on('invoice_data.order_id', '=', 'orders.id');
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

            // SELECT
            $query->select([
                // Invoice
                DB::raw('COALESCE(invoice_data.invoice_number, orders.order_reference) AS invoice_number'),
                DB::raw('COALESCE(invoice_data.invoice_created_at, orders.created_at) AS invoice_date'),

                // Order Reference (kept as bill_id for backward compatibility)
                'orders.order_reference                    AS bill_id',
                'orders.order_reference                    AS order_reference',   // explicit order reference

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

                // GST breakdown
                DB::raw('COALESCE(line_agg.product_subtotal, 0) AS product_subtotal'),
                DB::raw('COALESCE(line_agg.shipping_without_gst, 0) AS shipping_without_gst'),
                DB::raw('COALESCE(line_agg.product_gst, 0) AS product_gst'),
                DB::raw('COALESCE(line_agg.shipping_gst, 0) AS shipping_gst'),
                DB::raw('COALESCE(line_agg.total_gst, 0) AS total_gst'),
                DB::raw('ROUND(COALESCE(line_agg.intra_state_tax, 0) / 2, 2) AS cgst'),
                DB::raw('ROUND(COALESCE(line_agg.intra_state_tax, 0) / 2, 2) AS sgst'),
                DB::raw('ROUND(COALESCE(line_agg.inter_state_tax, 0), 2) AS igst'),

                // Gross (GST-inclusive)
                DB::raw('COALESCE(line_agg.product_gross, 0) AS product_gross'),
                DB::raw('COALESCE(line_agg.shipping_charge_gross, 0) AS shipping_gross'),

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

                DB::raw('COALESCE(line_agg.shipping_charge_gross, 0) AS shipping_charge'),

                DB::raw("CASE WHEN orders.status = 'delivered' THEN 'delivered' ELSE COALESCE(line_agg.overall_delivery_status, 'confirmed') END AS delivery_status"),
                DB::raw("CASE WHEN COALESCE(refund_sum.total_refunded, 0) > 0 THEN 'TRUE' ELSE 'FALSE' END AS is_refunded"),
                'orders.refund_status                      AS refund_status',
                DB::raw('0 AS refunded_items'),
                'refund_sum.last_refunded_at               AS last_refund_date',
                'refund_sum.first_refund_created_at        AS refunded_at',

                // Gateway transaction id (payment status removed)
                'orders.gateway_transaction_id             AS gateway_transaction_id',

                'orders.payment_gateway                    AS payment_gateway',
                'orders.courier_company                    AS courier_company',
                'orders.courier_tracking_number            AS courier_tracking_number',

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

            $data = $paginated->getCollection()->map(function ($row) use ($stateNormalize, $gatewayDisplayMap) {
                $deliveryRaw = strtoupper(trim((string) ($row->delivery_state ?? '')));
                $billingRaw  = strtoupper(trim((string) ($row->billing_state ?? '')));

                $deliveryState = $stateNormalize[$deliveryRaw] ?? ($row->delivery_state ?? '');
                $billingState  = $stateNormalize[$billingRaw]  ?? ($row->billing_state ?? '');

                // Parse products (gross line_total + shipping_charge + item_reference_id)
                $products = [];
                if (!empty($row->products_concat)) {
                    foreach (explode('||', $row->products_concat) as $productStr) {
                        $parts = explode('::', $productStr);
                        if (count($parts) >= 10) {
                            $products[] = [
                                'item_reference_id'  => $parts[9],                       // NEW
                                'name'            => $parts[0],
                                'slug'            => $parts[1],
                                'product_code'    => $parts[2],
                                'hsn_code'        => $parts[3],
                                'quantity'        => (int) $parts[4],
                                'unit_price'      => round((float) $parts[5], 2),     // GST-inclusive
                                'line_total'      => round((float) $parts[6], 2),     // GST-inclusive
                                'tax_rate'        => round((float) $parts[7], 2),
                                'shipping_charge' => round((float) $parts[8], 2),     // GST-inclusive
                            ];
                        }
                    }
                }

                // Parse line couriers
                $lineCouriers = [];
                if (!empty($row->courier_concat)) {
                    foreach (explode('||', $row->courier_concat) as $courierStr) {
                        $parts = explode('::', $courierStr);
                        if (count($parts) >= 2) {
                            $lineCouriers[] = [
                                'courier_company'         => $parts[0] ?: null,
                                'courier_tracking_number' => $parts[1] ?: null,
                            ];
                        }
                    }
                }

                $productNames = implode(', ', array_column($products, 'name'));

                $paymentGatewayRaw     = strtolower(trim((string) ($row->payment_gateway ?? '')));
                $paymentGatewayDisplay = $gatewayDisplayMap[$paymentGatewayRaw] ?? ($row->payment_gateway ?? '');

                return [
                    // Invoice
                    'Invoice Number'    => (string) ($row->invoice_number ?? ''),
                    'Invoice Date'      => $row->invoice_date ? Carbon::parse($row->invoice_date)->format('d-m-Y') : '',

                    // References (both)
                    'Order Reference'   => (string) ($row->order_reference ?? ''),   // NEW
                    'Bill Id'           => (string) $row->bill_id,                   // kept for backward compat

                    'Order Date'        => $row->order_date ? Carbon::parse($row->order_date)->format('d-m-Y H:i') : '',

                    // Customer
                    'Customer Name'     => $row->customer_name ?? '',
                    'User Account Type' => $row->account_type ?? '',
                    'Customer Mobile'   => $row->customer_mobile ?? '',
                    'Customer Email'    => $row->customer_email ?? '',
                    'Customer GSTIN'    => $row->customer_gstin ?? 'URP',

                    // Products (each carries item_reference)
                    'Product Names'     => $productNames,
                    'Products'          => $products,

                    // Amounts
                    'Gross Total'       => round((float) $row->gross_total, 2),
                    'Order Amount'      => round((float) $row->order_amount, 2),
                    // 'Refunded Amount'   => round((float) ($row->refunded_amount ?? 0), 2),
                    'Net Order Amount'  => round((float) $row->net_order_amount, 2),

                    // GST breakdown
                    'Product Subtotal'  => round((float) $row->product_subtotal, 2),
                    'Shipping Without GST' => round((float) $row->shipping_without_gst, 2),
                    'Product GST'       => round((float) $row->product_gst, 2),
                    'Shipping GST'      => round((float) $row->shipping_gst, 2),
                    'Total GST'         => round((float) $row->total_gst, 2),
                    'Tax Breakdown'     => [
                        'cgst' => round((float) $row->cgst, 2),
                        'sgst' => round((float) $row->sgst, 2),
                        'igst' => round((float) $row->igst, 2),
                    ],

                    'Total List Price'  => round((float) $row->total_list_price, 2),
                    'Discount Amount'   => round((float) $row->discount_amount, 2),
                    'CV'                => round((float) $row->cv, 2),
                    'User Name'         => $row->user_name ?? '',

                    // Order
                    'Order Status'      => $row->order_status,
                    'Total Items'       => (int) $row->total_items,
                    'Delivery Status'   => $row->delivery_status ?? '',

                    // Dates
                    'Confirmed At'      => $row->confirmed_at  ? Carbon::parse($row->confirmed_at)->format('d-m-Y H:i')  : '',
                    'Dispatched At'     => $row->dispatched_at ? Carbon::parse($row->dispatched_at)->format('d-m-Y H:i') : '',
                    'Shipping Date'     => $row->shipping_date ? Carbon::parse($row->shipping_date)->format('d-m-Y H:i') : '',
                    'Delivered At'      => $row->delivered_at  ? Carbon::parse($row->delivered_at)->format('d-m-Y H:i')  : '',
                    'Refunded At'       => $row->refunded_at   ? Carbon::parse($row->refunded_at)->format('d-m-Y H:i')   : '',
                    'Last Refund Date'  => $row->last_refund_date ? Carbon::parse($row->last_refund_date)->format('d-m-Y H:i') : '',

                    // Delivery address
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

                    // Billing address
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

                    // Shipping
                    'Shipping Charge'   => round((float) $row->shipping_charge, 2), // GST-inclusive

                    // Payment (status removed)
                    'Payment Gateway'         => $paymentGatewayDisplay,
                    'Transaction Gateway Id'  => $row->gateway_transaction_id ?? null,

                    // Courier
                    // 'Courier Company'         => $row->courier_company ?? '',
                    // 'Courier Tracking Number' => $row->courier_tracking_number ?? '',
                    'Line Couriers'           => $lineCouriers,

                    // Refund
                    // 'Is Refunded'             => $row->is_refunded,
                    // 'Refund Status'           => $row->refund_status ?? '',
                    // 'Refunded Items'          => (int) $row->refunded_items,
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

    // public function financeSalesReport(Request $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'status'   => 'nullable|string|in:confirmed,dispatched,shipped,delivered',
    //         'from'     => 'nullable|date',
    //         'to'       => 'nullable|date|after_or_equal:from',
    //         'per_page' => 'nullable|integer|min:1|max:500',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation failed.',
    //             'errors'  => $validator->errors(),
    //         ], 422);
    //     }

    //     try {
    //         $allowedStatuses = $request->filled('status')
    //             ? [$request->status]
    //             : ['undelivered', 'dispatched', 'shipped', 'delivered'];

    //         $perPage = (int) $request->input('per_page', 100);

    //         // Refund subquery
    //         $refundSub = DB::table('refunds')
    //             ->select('order_id')
    //             ->selectRaw('SUM(amount) AS total_refunded')
    //             ->selectRaw('MAX(completed_at) AS last_refunded_at')
    //             ->selectRaw('MAX(created_at) AS first_refund_created_at')
    //             ->where('status', 'completed')
    //             ->groupBy('order_id');

    //         // GSTIN subquery
    //         $gstinSub = DB::table('distributor_profiles')
    //             ->select('user_id')
    //             ->selectRaw('MAX(gst_in) AS gst_in')
    //             ->whereNotNull('gst_in')
    //             ->groupBy('user_id');

    //         // LINE AGGREGATE SUBQUERY
    //         $lineAggSub = DB::table('order_lines')
    //             ->join('products', 'products.id', '=', 'order_lines.product_id')
    //             ->join('orders', 'orders.id', '=', 'order_lines.order_id')
    //             ->leftJoin('addresses as delivery_addr', 'delivery_addr.id', '=', 'orders.delivery_address_id')
    //             ->select('order_lines.order_id')
    //             ->selectRaw('SUM(order_lines.quantity) AS total_items')
    //             ->selectRaw('SUM(order_lines.quantity * COALESCE(products.commission_value, 0)) AS total_cv')
    //             ->selectRaw('SUM(COALESCE(order_lines.shipping_charge, 0)) AS total_shipping_charge')
    //             ->selectRaw('MAX(order_lines.shipped_at) AS max_shipped_at')
    //             ->selectRaw('MAX(order_lines.dispatched_at) AS max_dispatched_at')
    //             ->selectRaw('MAX(order_lines.delivered_at)  AS max_delivered_at')
    //             ->selectRaw('MIN(order_lines.created_at)    AS min_line_created_at')
    //             ->selectRaw('MAX(delivery_addr.state) AS shipping_state')
    //             ->selectRaw('
    //             SUM(
    //                 (order_lines.line_total + COALESCE(order_lines.shipping_charge, 0))
    //                 - ((order_lines.line_total + COALESCE(order_lines.shipping_charge, 0)) / (1 + (COALESCE(order_lines.gst_rate, 0) / 100)))
    //             ) AS derived_total_tax
    //         ')
    //             ->selectRaw("
    //             SUM(
    //                 CASE
    //                     WHEN UPPER(TRIM(delivery_addr.state)) IN ('PUNJAB','PB')
    //                     THEN (order_lines.line_total + COALESCE(order_lines.shipping_charge, 0))
    //                         - ((order_lines.line_total + COALESCE(order_lines.shipping_charge, 0)) / (1 + (COALESCE(order_lines.gst_rate, 0) / 100)))
    //                     ELSE 0
    //                 END
    //             ) AS derived_intra_tax
    //         ")
    //             ->selectRaw("
    //             SUM(
    //                 CASE
    //                     WHEN UPPER(TRIM(delivery_addr.state)) NOT IN ('PUNJAB','PB')
    //                         OR delivery_addr.state IS NULL
    //                     THEN (order_lines.line_total + COALESCE(order_lines.shipping_charge, 0))
    //                         - ((order_lines.line_total + COALESCE(order_lines.shipping_charge, 0)) / (1 + (COALESCE(order_lines.gst_rate, 0) / 100)))
    //                     ELSE 0
    //                 END
    //             ) AS derived_inter_tax
    //         ")
    //             ->selectRaw("
    //             CASE
    //                 WHEN SUM(CASE WHEN order_lines.delivery_status = 'delivered' THEN 1 ELSE 0 END) = COUNT(*) THEN 'delivered'
    //                 WHEN SUM(CASE WHEN order_lines.delivery_status = 'shipped' THEN 1 ELSE 0 END) > 0 THEN 'shipped'
    //                 WHEN SUM(CASE WHEN order_lines.delivery_status = 'dispatched' THEN 1 ELSE 0 END) > 0 THEN 'dispatched'
    //                 ELSE 'confirmed'
    //             END AS overall_delivery_status
    //         ")
    //             ->groupBy('order_lines.order_id');

    //         // PRODUCTS SUBQUERY — name, slug, product_code, qty, unit_price, line_total
    //         $productAggSub = DB::table('order_lines')
    //             ->join('products', 'products.id', '=', 'order_lines.product_id')
    //             ->select('order_lines.order_id')
    //             ->selectRaw("
    //             GROUP_CONCAT(
    //                 DISTINCT CONCAT_WS('::',
    //                     products.name,
    //                     products.slug,
    //                     products.hsn_code,
    //                     products.product_code,
    //                     order_lines.quantity,
    //                     order_lines.unit_price,
    //                     order_lines.line_total
    //                 )
    //                 ORDER BY products.name SEPARATOR '||'
    //             ) AS products_concat
    //         ")
    //             ->groupBy('order_lines.order_id');

    //         // LINE SHIPPING DETAILS SUBQUERY
    //         $shippingAggSub = DB::table('order_shipping_details as osd')
    //             ->join('order_lines as ol', 'ol.id', '=', 'osd.order_line_id')
    //             ->select('ol.order_id')
    //             ->selectRaw("
    //             GROUP_CONCAT(
    //                 DISTINCT CONCAT_WS('::',
    //                     COALESCE(osd.courier_company, ''),
    //                     COALESCE(osd.courier_tracking_number, '')
    //                 )
    //                 ORDER BY osd.id SEPARATOR '||'
    //             ) AS courier_concat
    //         ")
    //             ->groupBy('ol.order_id');

    //         // BASE QUERY
    //         $query = Order::query()
    //             ->join('users', 'users.id', '=', 'orders.user_id')
    //             ->leftJoinSub($refundSub, 'refund_sum', function ($join) {
    //                 $join->on('refund_sum.order_id', '=', 'orders.id');
    //             })
    //             ->leftJoinSub($gstinSub, 'gstin_data', function ($join) {
    //                 $join->on('gstin_data.user_id', '=', 'users.id');
    //             })
    //             ->leftJoinSub($lineAggSub, 'line_agg', function ($join) {
    //                 $join->on('line_agg.order_id', '=', 'orders.id');
    //             })
    //             ->leftJoinSub($productAggSub, 'product_agg', function ($join) {
    //                 $join->on('product_agg.order_id', '=', 'orders.id');
    //             })
    //             ->leftJoinSub($shippingAggSub, 'shipping_agg', function ($join) {
    //                 $join->on('shipping_agg.order_id', '=', 'orders.id');
    //             })
    //             ->whereIn('line_agg.overall_delivery_status', $allowedStatuses)
    //             ->whereNull('orders.deleted_at');

    //         if ($request->filled('from')) {
    //             $query->where('orders.created_at', '>=', $request->from . ' 00:00:00');
    //         }
    //         if ($request->filled('to')) {
    //             $query->where('orders.created_at', '<=', $request->to . ' 23:59:59');
    //         }

    //         // SELECT
    //         $query->select([
    //             'orders.order_reference                    AS bill_id',
    //             'orders.created_at                         AS order_date',
    //             'users.full_name                           AS customer_name',
    //             'users.phone                               AS customer_mobile',
    //             'users.email                               AS customer_email',
    //             'orders.subtotal                           AS gross_total',
    //             'orders.total_payable                      AS order_amount',
    //             DB::raw('COALESCE(refund_sum.total_refunded, 0) AS refunded_amount'),
    //             DB::raw('(orders.total_payable - COALESCE(refund_sum.total_refunded, 0)) AS net_order_amount'),
    //             'orders.status                             AS order_status',
    //             DB::raw('COALESCE(line_agg.total_items, 0) AS total_items'),
    //             DB::raw('COALESCE(line_agg.derived_total_tax, 0) AS total_tax'),
    //             DB::raw('ROUND(COALESCE(line_agg.derived_intra_tax, 0) / 2, 2) AS cgst'),
    //             DB::raw('ROUND(COALESCE(line_agg.derived_intra_tax, 0) / 2, 2) AS sgst'),
    //             DB::raw('ROUND(COALESCE(line_agg.derived_inter_tax, 0), 2) AS igst'),
    //             'orders.subtotal                           AS total_list_price',
    //             'orders.coupon_discount                    AS discount_amount',
    //             DB::raw('COALESCE(line_agg.total_cv, 0) AS cv'),
    //             'users.distributor_id                      AS user_name',
    //             'gstin_data.gst_in                         AS customer_gstin',
    //             'line_agg.max_shipped_at                   AS shipping_date',
    //             'line_agg.min_line_created_at              AS confirmed_at',
    //             'line_agg.max_dispatched_at                AS dispatched_at',
    //             'line_agg.max_delivered_at                 AS delivered_at',
    //             'line_agg.shipping_state                   AS customer_shipping_state',
    //             DB::raw('COALESCE(line_agg.total_shipping_charge, 0) AS shipping_charge'),
    //             DB::raw("CASE WHEN orders.status = 'delivered' THEN 'delivered' ELSE COALESCE(line_agg.overall_delivery_status, 'confirmed') END AS delivery_status"),
    //             DB::raw("CASE WHEN COALESCE(refund_sum.total_refunded, 0) > 0 THEN 'TRUE' ELSE 'FALSE' END AS is_refunded"),
    //             'orders.refund_status                      AS refund_status',
    //             DB::raw('0 AS refunded_items'),
    //             'refund_sum.last_refunded_at               AS last_refund_date',
    //             'refund_sum.first_refund_created_at        AS refunded_at',

    //             'orders.payment_gateway                    AS payment_gateway',
    //             'orders.courier_company                    AS courier_company',
    //             'orders.courier_tracking_number            AS courier_tracking_number',

    //             'product_agg.products_concat               AS products_concat',
    //             'shipping_agg.courier_concat               AS courier_concat',
    //         ])
    //             ->orderBy('orders.created_at', 'desc');

    //         $paginated = $query->paginate($perPage);

    //         // FORMAT
    //         $stateNormalize = [
    //             'RAJISTHAN' => 'Rajasthan',
    //             'MAADHYA PRADESH' => 'Madhya Pradesh',
    //             'HIMANCHAL PRADESH' => 'Himachal Pradesh',
    //             'WEST BANGAL' => 'West Bengal',
    //             'KARNATKA' => 'Karnataka',
    //             'KARANATAKA' => 'Karnataka',
    //             'CHHATISGRAH' => 'Chhattisgarh',
    //             'CHHATTSGARH' => 'Chhattisgarh',
    //             'GUJRAT' => 'Gujarat',
    //             'HARIYANA' => 'Haryana',
    //             'U.P' => 'Uttar Pradesh',
    //             'UP' => 'Uttar Pradesh',
    //         ];

    //         //  Gateway display mapping (axispg → Freecharge)
    //         $gatewayDisplayMap = [
    //             'axispg'   => 'Freecharge',
    //             'razorpay' => 'Razorpay',
    //             'payu'     => 'PayU',
    //             'paytm'    => 'Paytm',
    //         ];

    //         $data = $paginated->getCollection()->map(function ($row) use ($stateNormalize, $gatewayDisplayMap) {
    //             $rawState = strtoupper(trim((string) ($row->customer_shipping_state ?? '')));
    //             $state = $stateNormalize[$rawState] ?? ($row->customer_shipping_state ?? '');

    //             // Parse products
    //             $products = [];
    //             if (!empty($row->products_concat)) {
    //                 foreach (explode('||', $row->products_concat) as $productStr) {
    //                     $parts = explode('::', $productStr);
    //                     if (count($parts) >= 6) {
    //                         $qty       = (int) $parts[3];
    //                         $unitPrice = (float) $parts[4];
    //                         $lineTotal = (float) $parts[5];

    //                         $products[] = [
    //                             'name'         => $parts[0],
    //                             'slug'         => $parts[1],
    //                             'product_code' => $parts[2],
    //                             'quantity'     => $qty,
    //                             'unit_price'   => round($unitPrice, 2),
    //                             'line_total'   => round($lineTotal, 2),
    //                             'subtotal_per_unit' => $qty > 0 ? round($lineTotal / $qty, 2) : 0,
    //                         ];
    //                     }
    //                 }
    //             }

    //             // Parse line couriers
    //             $lineCouriers = [];
    //             if (!empty($row->courier_concat)) {
    //                 foreach (explode('||', $row->courier_concat) as $courierStr) {
    //                     $parts = explode('::', $courierStr);
    //                     if (count($parts) >= 2) {
    //                         $lineCouriers[] = [
    //                             'courier_company'         => $parts[0] ?: null,
    //                             'courier_tracking_number' => $parts[1] ?: null,
    //                         ];
    //                     }
    //                 }
    //             }

    //             $productNames = implode(', ', array_column($products, 'name'));

    //             //  Payment gateway display (axispg → Freecharge)
    //             $paymentGatewayRaw     = strtolower(trim((string) ($row->payment_gateway ?? '')));
    //             $paymentGatewayDisplay = $gatewayDisplayMap[$paymentGatewayRaw] ?? ($row->payment_gateway ?? '');

    //             return [
    //                 'Bill Id'                 => (string) $row->bill_id,
    //                 'Order Date'              => $row->order_date ? Carbon::parse($row->order_date)->format('d-m-Y H:i') : '',
    //                 'Customer Name'           => $row->customer_name ?? '',
    //                 'Customer Mobile'         => $row->customer_mobile ?? '',
    //                 'Customer Email'          => $row->customer_email ?? '',

    //                 'Product Names'           => $productNames,
    //                 'Products'                => $products,

    //                 'Gross Total'             => round((float) $row->gross_total, 2),
    //                 'Order Amount'            => round((float) $row->order_amount, 2),
    //                 'Refunded Amount'         => round((float) ($row->refunded_amount ?? 0), 2),
    //                 'Net Order Amount'        => round((float) $row->net_order_amount, 2),
    //                 'Order Status'            => $row->order_status,
    //                 'Total Items'             => (int) $row->total_items,
    //                 'Total Tax'               => round((float) $row->total_tax, 2),
    //                 'CGST'                    => round((float) $row->cgst, 2),
    //                 'SGST'                    => round((float) $row->sgst, 2),
    //                 'IGST'                    => round((float) $row->igst, 2),
    //                 'Total List Price'        => round((float) $row->total_list_price, 2),
    //                 'Discount Amount'         => round((float) $row->discount_amount, 2),
    //                 'CV'                      => round((float) $row->cv, 2),
    //                 'User Name'               => $row->user_name ?? '',
    //                 'Customer GSTIN'          => $row->customer_gstin ?? '',
    //                 'Shipping Date'           => $row->shipping_date ? Carbon::parse($row->shipping_date)->format('d-m-Y H:i') : '',
    //                 'Confirmed At'            => $row->confirmed_at  ? Carbon::parse($row->confirmed_at)->format('d-m-Y H:i')  : '',
    //                 'Dispatched At'           => $row->dispatched_at ? Carbon::parse($row->dispatched_at)->format('d-m-Y H:i') : '',
    //                 'Delivered At'            => $row->delivered_at  ? Carbon::parse($row->delivered_at)->format('d-m-Y H:i')  : '',
    //                 'Refunded At'             => $row->refunded_at   ? Carbon::parse($row->refunded_at)->format('d-m-Y H:i')   : '',
    //                 'Customer Shipping State' => $state,
    //                 'Shipping Charge'         => round((float) $row->shipping_charge, 2),
    //                 'Delivery Status'         => $row->delivery_status ?? '',

    //                 //  axispg → Freecharge

    //                 'Payment Gateway'         => $paymentGatewayDisplay,
    //                 'Courier Company'         => $row->courier_company ?? '',
    //                 'Courier Tracking Number' => $row->courier_tracking_number ?? '',
    //                 'Line Couriers'           => $lineCouriers,

    //                 'Is Refunded'             => $row->is_refunded,
    //                 'Refund Status'           => $row->refund_status ?? '',
    //                 'Refunded Items'          => (int) $row->refunded_items,
    //                 'Last Refund Date'        => $row->last_refund_date ? Carbon::parse($row->last_refund_date)->format('d-m-Y H:i') : '',
    //             ];
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'data'    => $data,
    //             'meta'    => [
    //                 'total'        => $paginated->total(),
    //                 'per_page'     => $paginated->perPage(),
    //                 'current_page' => $paginated->currentPage(),
    //                 'last_page'    => $paginated->lastPage(),
    //             ],
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Sales report failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    //         return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    //     }
    // }

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

            // Invoice aggregate
            $invoiceSub = DB::table('invoices')
                ->select('order_id')
                ->selectRaw('MIN(invoice_number) AS invoice_number')
                ->selectRaw('MIN(created_at) AS invoice_created_at')
                ->groupBy('order_id');

            /*
         * LINE AGGREGATE
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
                ->selectRaw('
                SUM(
                    order_lines.quantity * COALESCE(products.commission_value, 0)
                ) AS total_cv
            ')
                ->selectRaw("SUM($productWithoutTax) AS product_subtotal")
                ->selectRaw("SUM($shippingWithoutTax) AS shipping_without_gst")
                ->selectRaw("SUM($productTax) AS product_gst")
                ->selectRaw("SUM($shippingTax) AS shipping_gst")
                ->selectRaw("SUM($productTax + $shippingTax) AS total_gst")
                ->selectRaw("
                SUM(
                    CASE
                        WHEN billing_addr.state IS NOT NULL
                        AND delivery_addr.state IS NOT NULL
                        AND TRIM(billing_addr.state) != ''
                        AND TRIM(delivery_addr.state) != ''
                        AND UPPER(TRIM(billing_addr.state)) = UPPER(TRIM(delivery_addr.state))
                        THEN $productTax + $shippingTax
                        ELSE 0
                    END
                ) AS intra_state_tax
            ")
                ->selectRaw("
                SUM(
                    CASE
                        WHEN billing_addr.state IS NULL
                            OR delivery_addr.state IS NULL
                            OR TRIM(billing_addr.state) = ''
                            OR TRIM(delivery_addr.state) = ''
                            OR UPPER(TRIM(billing_addr.state)) != UPPER(TRIM(delivery_addr.state))
                        THEN $productTax + $shippingTax
                        ELSE 0
                    END
                ) AS inter_state_tax
            ")
                ->selectRaw('SUM(COALESCE(order_lines.shipping_charge, 0)) AS shipping_gross')
                ->selectRaw('MAX(order_lines.shipped_at) AS max_shipped_at')
                ->selectRaw('MAX(order_lines.dispatched_at) AS max_dispatched_at')
                ->selectRaw('MAX(order_lines.delivered_at) AS max_delivered_at')
                ->selectRaw('MIN(order_lines.created_at) AS min_line_created_at')
                ->selectRaw('MAX(delivery_addr.state) AS delivery_state')
                ->selectRaw('MAX(billing_addr.state) AS billing_state')
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
         * PRODUCT DETAILS
         *
         * Now includes:
         *   - order_line_id (0) — needed to attach per-line couriers
         *   - order_lines.item_reference_id (1)
         *   - ... existing 9 fields ...
         *
         * Layout (11 fields):
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
         */
            $productAggSub = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->leftJoin('tax_categories', 'tax_categories.id', '=', 'products.tax_category_id')

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
                        COALESCE(order_lines.shipping_charge, 0)
                    )
                    ORDER BY products.name SEPARATOR '||'
                ) AS products_concat
            ")

                ->groupBy('order_lines.order_id');

            /*
         * COURIER AGGREGATE (per line)
         *
         * Builds a concat keyed by order_line_id so each product can be
         * matched to the courier(s) that dispatched THAT line.
         * Format: "order_line_id::courier_company::courier_tracking_number"
         * joined by '||'.
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
                ->selectRaw('MAX(osd.courier_company) AS courier_company')
                ->selectRaw('MAX(osd.courier_tracking_number) AS courier_tracking_number')
                ->selectRaw('MAX(osd.courier_delivery_date) AS courier_delivery_date')
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
                ->leftJoinSub($invoiceSub, 'invoice_data', function ($join) {
                    $join->on('invoice_data.order_id', '=', 'orders.id');
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
         * SELECT
         */
            $query->select([
                DB::raw('COALESCE(invoice_data.invoice_number, orders.order_reference) AS bill_id'),

                // NEW: explicit order_reference
                'orders.order_reference AS order_reference',

                'orders.created_at AS order_date',

                DB::raw('COALESCE(invoice_data.invoice_created_at, orders.created_at) AS invoice_date'),

                'users.full_name AS customer_name',
                'users.account_type AS account_type',
                'users.phone AS customer_mobile',
                'users.email AS customer_email',

                DB::raw('
                (
                    COALESCE(line_agg.product_subtotal, 0)
                    + COALESCE(line_agg.shipping_without_gst, 0)
                ) AS subtotal
            '),

                'orders.total_payable AS order_amount',

                DB::raw('COALESCE(refund_sum.total_refunded, 0) AS refunded_amount'),

                DB::raw('
                (orders.total_payable - COALESCE(refund_sum.total_refunded, 0))
                AS net_order_amount
            '),

                'orders.status AS order_status',

                DB::raw('COALESCE(line_agg.total_items, 0) AS total_items'),

                'line_agg.product_subtotal',
                'line_agg.shipping_without_gst',
                'line_agg.product_gst',
                'line_agg.shipping_gst',
                'line_agg.total_gst',

                DB::raw('ROUND(COALESCE(line_agg.intra_state_tax, 0) / 2, 2) AS cgst'),
                DB::raw('ROUND(COALESCE(line_agg.intra_state_tax, 0) / 2, 2) AS sgst'),
                DB::raw('ROUND(COALESCE(line_agg.inter_state_tax, 0), 2) AS igst'),

                'orders.coupon_discount AS discount_amount',

                DB::raw('COALESCE(line_agg.total_cv, 0) AS cv'),

                'users.distributor_id AS user_name',
                'gstin_data.gst_in AS customer_gstin',

                'line_agg.max_shipped_at AS shipping_date',
                'line_agg.min_line_created_at AS confirmed_at',
                'line_agg.max_dispatched_at AS dispatched_at',
                'line_agg.max_delivered_at AS delivered_at',

                'line_agg.billing_state',
                'line_agg.delivery_state',

                DB::raw('COALESCE(line_agg.shipping_without_gst, 0) AS shipping_charge'),

                'line_agg.overall_delivery_status AS delivery_status',

                DB::raw("
                CASE
                    WHEN COALESCE(refund_sum.total_refunded, 0) > 0
                    THEN 'TRUE'
                    ELSE 'FALSE'
                END AS is_refunded
            "),

                'orders.refund_status AS refund_status',

                DB::raw('0 AS refunded_items'),

                'refund_sum.last_refunded_at AS last_refund_date',
                'refund_sum.first_refund_created_at AS refunded_at',

                'orders.payment_gateway AS payment_gateway',
                'orders.gateway_transaction_id AS gateway_transaction_id',

                'shipping_agg.courier_company AS courier_company',
                'shipping_agg.courier_tracking_number AS courier_tracking_number',
                'shipping_agg.courier_delivery_date AS courier_delivery_date',

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

            /*
         * FORMAT RESPONSE
         */
            $data = $paginated->getCollection()->map(function ($row) use (
                $stateNormalize,
                $gatewayDisplayMap
            ) {
                $billingRaw = strtoupper(trim((string) ($row->billing_state ?? '')));
                $deliveryRaw = strtoupper(trim((string) ($row->delivery_state ?? '')));

                $billingState = $stateNormalize[$billingRaw] ?? ($row->billing_state ?? '');
                $deliveryState = $stateNormalize[$deliveryRaw] ?? ($row->delivery_state ?? '');

                /*
             * Parse couriers first, keyed by order_line_id.
             * Format in concat: "order_line_id::courier_company::courier_tracking_number"
             * Multiple couriers per line are possible → array of arrays.
             */
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

                // Parse products, attaching that line's couriers
                $products = [];

                if (!empty($row->products_concat)) {
                    foreach (explode('||', $row->products_concat) as $productStr) {
                        $parts = explode('::', $productStr);

                        // 11 fields now
                        if (count($parts) >= 11) {
                            $lineId = (int) $parts[0];
                            $itemReferenceId = $parts[1];

                            $qty = (int) $parts[6];

                            $unitPrice = (float) $parts[7];
                            $lineTotal = (float) $parts[8];
                            $taxRate = (float) $parts[9];
                            $shippingCharge = (float) $parts[10];

                            $divisor = 1 + ($taxRate / 100);

                            $lineTotalWithoutGst = $divisor > 0
                                ? $lineTotal / $divisor
                                : $lineTotal;

                            $unitPriceWithoutGst = $qty > 0
                                ? $lineTotalWithoutGst / $qty
                                : 0;

                            $productGst = $lineTotal - $lineTotalWithoutGst;

                            $shippingWithoutGst = $divisor > 0
                                ? $shippingCharge / $divisor
                                : $shippingCharge;

                            $shippingGst = $shippingCharge - $shippingWithoutGst;

                            $products[] = [
                                'order_line_id' => $lineId,
                                'item_reference_id' => $itemReferenceId,

                                'name' => $parts[2],
                                'slug' => $parts[3],
                                'hsn_code' => $parts[4],
                                'product_code' => $parts[5],

                                'quantity' => $qty,

                                'unit_price_without_gst' => round($unitPriceWithoutGst, 2),
                                'line_total_without_gst' => round($lineTotalWithoutGst, 2),

                                'tax_rate' => round($taxRate, 2),
                                'gst_amount' => round($productGst, 2),

                                'shipping_charge_without_gst' => round($shippingWithoutGst, 2),
                                'shipping_gst' => round($shippingGst, 2),

                                // Couriers that dispatched THIS line
                                'line_couriers' => $couriersByLine[$lineId] ?? [],
                            ];
                        }
                    }
                }

                $productNames = implode(', ', array_column($products, 'name'));

                $paymentGatewayRaw = strtolower(trim((string) ($row->payment_gateway ?? '')));
                $paymentGatewayDisplay = $gatewayDisplayMap[$paymentGatewayRaw]
                    ?? ($row->payment_gateway ?? '');

                $productSubtotal = round((float) $row->product_subtotal, 2);
                $shippingWithoutGst = round((float) $row->shipping_without_gst, 2);

                return [
                    // Invoice
                    'invoice_number' => (string) ($row->bill_id ?? ''),
                    'invoice_date' => $row->invoice_date
                        ? Carbon::parse($row->invoice_date)->format('d-m-Y')
                        : '',

                    // NEW: explicit order reference
                    'order_reference' => (string) ($row->order_reference ?? ''),


                    // Customer
                    'customer_name' => $row->customer_name ?? '',
                    'account_type' => $row->account_type ?? '',
                    'customer_mobile' => $row->customer_mobile ?? '',
                    'customer_email' => $row->customer_email ?? '',
                    'customer_gstin' => $row->customer_gstin ?? 'URP',

                    // Addresses
                    'billing_state' => $billingState,
                    'delivery_state' => $deliveryState,

                    // Products (each carries item_reference_id + its own line_couriers)
                    'product_names' => $productNames,
                    'products' => $products,

                    // Amounts excluding GST
                    'product_subtotal' => $productSubtotal,
                    'shipping_charge_without_gst' => $shippingWithoutGst,
                    'subtotal' => round($productSubtotal + $shippingWithoutGst, 2),

                    // GST breakdown
                    'product_gst' => round((float) $row->product_gst, 2),
                    'shipping_gst' => round((float) $row->shipping_gst, 2),
                    'total_gst' => round((float) $row->total_gst, 2),

                    'tax_breakdown' => [
                        'cgst' => round((float) $row->cgst, 2),
                        'sgst' => round((float) $row->sgst, 2),
                        'igst' => round((float) $row->igst, 2),
                    ],

                    // Final amounts
                    'order_amount' => round((float) $row->order_amount, 2),
                    'discount_amount' => round((float) $row->discount_amount, 2),
                    'net_order_amount' => round((float) $row->net_order_amount, 2),

                    // Order
                    'order_status' => $row->order_status,
                    'total_items' => (int) $row->total_items,

                    // Commission
                    'cv' => round((float) $row->cv, 2),
                    'user_name' => $row->user_name ?? '',

                    // Dates
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

                    // Payment
                    'payment_gateway' => $paymentGatewayDisplay,
                    'gateway_transaction_id' => $row->gateway_transaction_id ?? null,

                    // Courier (order-level fallback)
                    // 'courier_company' => $row->courier_company ?? '',
                    // 'courier_tracking_number' => $row->courier_tracking_number ?? '',
                    // 'courier_delivery_date' => $row->courier_delivery_date
                    //     ? Carbon::parse($row->courier_delivery_date)->format('d-m-Y')
                    //     : '',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'total' => $paginated->total(),
                    'per_page' => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
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