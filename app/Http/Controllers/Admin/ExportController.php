<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\CsvExportService;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(
        protected CsvExportService $csv
    ) {}

    public function ordersFullCsv(Request $request)
    {
        $query = Order::query()
            ->with([
                'user:id,full_name,email,phone,account_type,distributor_status,country',
                'lines' => function ($q) {
                    $q->select(
                        'id',
                        'order_id',
                        'item_reference_id',
                        'product_id',
                        'variant_id',
                        'quantity',
                        'unit_price',
                        'gst_rate',
                        'gst_amount',
                        'line_total',
                        'shipping_charge',
                        'delivery_status',
                        'return_status',
                        'dispatched_at',
                        'shipped_at',
                        'delivered_at',
                        'cancelled_at',
                        'cancellation_reason',
                        'return_at',
                        'return_reason',
                        'is_returnable',
                        'is_replacement'
                    )->orderBy('id');
                },
            ])
            ->select([
                // Order core
                'id',
                'order_reference',
                'user_id',
                'order_type',
                'is_replacement',
                'parent_order_id',
                'subtotal',
                'total_gst',
                'total_cgst',
                'total_sgst',
                'total_igst',
                'shipping_charge',
                'coupon_code',
                'coupon_discount',
                'coin_redeemed',
                'coin_redeemed_amount',
                'total_payable',
                'amount_paid',
                'status',
                'delivery_status',
                'return_status',
                'refund_status',
                'refunded_at',
                // Payment
                'payment_gateway',
                'gateway_transaction_id',
                'checkout_type',
                // Courier
                'courier_company',
                'courier_tracking_number',
                'courier_status',
                'courier_delivery_date',
                // Timeline
                'confirmed_at',
                'shipped_at',
                'delivered_at',
                'cancelled_at',
                'created_at',
            ])
            ->orderByDesc('created_at');

        // ---------- Filters (optional) ----------
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->string('delivery_status'));
        }
        if ($request->filled('payment_gateway')) {
            $query->where('payment_gateway', $request->string('payment_gateway'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        // Only paid orders (jaisa paymentManagement me hai)
        if ($request->boolean('paid_only')) {
            $query->whereNotNull('gateway_transaction_id');
        }

        $headers = [
            // ---- Order ----
            'Order ID',
            'Order Reference',
            'Parent Order ID',
            'Is Replacement',
            'Order Type',
            'Order Status',
            'Order Delivery Status',
            'Order Return Status',
            'Order Refund Status',
            'Refunded At',
            'Subtotal',
            'Total GST',
            'Total CGST',
            'Total SGST',
            'Total IGST',
            'Shipping Charge',
            'Coupon Code',
            'Coupon Discount',
            'Coin Redeemed',
            'Coin Redeemed Amount',
            'Total Payable',
            'Amount Paid',
            // ---- Payment ----
            'Payment Gateway',
            'Gateway Transaction ID',
            'Checkout Type',
            // ---- Courier ----
            'Courier Company',
            'Courier Tracking Number',
            'Courier Status',
            'Courier Delivery Date',
            // ---- Order timeline ----
            'Order Confirmed At',
            'Order Shipped At',
            'Order Delivered At',
            'Order Cancelled At',
            'Order Created At',
            // ---- Customer ----
            'User ID',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Account Type',
            'Distributor Status',
            'Country',
            // ---- Order Line ----
            'Line ID',
            'Item Reference ID',
            'Product ID',
            'Variant ID',
            'Quantity',
            'Unit Price',
            'GST Rate',
            'GST Amount',
            'Line Total',
            'Line Shipping Charge',
            'Line Delivery Status',
            'Line Return Status',
            'Is Returnable',
            'Is Line Replacement',
            'Line Dispatched At',
            'Line Shipped At',
            'Line Delivered At',
            'Line Cancelled At',
            'Line Cancellation Reason',
            'Line Return At',
            'Line Return Reason',
        ];

        $filename = 'orders-full-' . now()->format('Y-m-d_His') . '.csv';

        return $this->csv->stream(
            $filename,
            $headers,
            $query,
            function (Order $order) {
                $rows = [];

                $orderCols = [
                    $order->id,
                    $order->order_reference,
                    $order->parent_order_id,
                    $order->is_replacement ? 'Yes' : 'No',
                    $order->order_type,
                    $order->status,
                    $order->delivery_status,
                    $order->return_status,
                    $order->refund_status,
                    optional($order->refunded_at)->toDateTimeString(),
                    number_format((float) $order->subtotal, 2, '.', ''),
                    number_format((float) $order->total_gst, 2, '.', ''),
                    number_format((float) $order->total_cgst, 2, '.', ''),
                    number_format((float) $order->total_sgst, 2, '.', ''),
                    number_format((float) $order->total_igst, 2, '.', ''),
                    number_format((float) $order->shipping_charge, 2, '.', ''),
                    $order->coupon_code,
                    number_format((float) $order->coupon_discount, 2, '.', ''),
                    $order->coin_redeemed,
                    number_format((float) $order->coin_redeemed_amount, 2, '.', ''),
                    number_format((float) $order->total_payable, 2, '.', ''),
                    number_format((float) $order->amount_paid, 2, '.', ''),
                    $order->payment_gateway,
                    $order->gateway_transaction_id,
                    $order->checkout_type,
                    $order->courier_company,
                    $order->courier_tracking_number,
                    $order->courier_status,
                    optional($order->courier_delivery_date)->toDateString(),
                    optional($order->confirmed_at)->toDateTimeString(),
                    optional($order->shipped_at)->toDateTimeString(),
                    optional($order->delivered_at)->toDateTimeString(),
                    optional($order->cancelled_at)->toDateTimeString(),
                    optional($order->created_at)->toDateTimeString(),
                ];

                $userCols = [
                    $order->user?->id,
                    $order->user?->full_name,
                    $order->user?->email,
                    $order->user?->phone,
                    $order->user?->account_type,
                    $order->user?->distributor_status,
                    $order->user?->country,
                ];

                // Ek order ki saari lines — alag alag row
                foreach ($order->lines as $line) {
                    $rows[] = array_merge($orderCols, $userCols, [
                        $line->id,
                        $line->item_reference_id,
                        $line->product_id,
                        $line->variant_id,
                        $line->quantity,
                        number_format((float) $line->unit_price, 2, '.', ''),
                        $line->gst_rate,
                        number_format((float) $line->gst_amount, 2, '.', ''),
                        number_format((float) $line->line_total, 2, '.', ''),
                        number_format((float) $line->shipping_charge, 2, '.', ''),
                        $line->delivery_status,
                        $line->return_status,
                        $line->is_returnable ? 'Yes' : 'No',
                        $line->is_replacement ? 'Yes' : 'No',
                        optional($line->dispatched_at)->toDateTimeString(),
                        optional($line->shipped_at)->toDateTimeString(),
                        optional($line->delivered_at)->toDateTimeString(),
                        optional($line->cancelled_at)->toDateTimeString(),
                        $line->cancellation_reason,
                        optional($line->return_at)->toDateTimeString(),
                        $line->return_reason,
                    ]);
                }

                // Agar order ki koi line nahi (rare), tab bhi ek row bhejo
                if (empty($rows)) {
                    $rows[] = array_merge($orderCols, $userCols, array_fill(0, 22, null));
                }

                return $rows;
            }
        );
    }
}
