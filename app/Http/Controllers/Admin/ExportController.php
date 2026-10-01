<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
                'user' => function ($q) {
                    $q->select(
                        'id',
                        'full_name',
                        'email',
                        'phone',
                        'account_type',
                        'country'
                    )->with([
                        'distributorProfile' => function ($dq) {
                            $dq->select(
                                'id',
                                'user_id',
                                'gst_in',
                                'company_name',
                                'encrypted_pan',
                                'encrypted_bank_account',
                                'bank_ifsc',
                                'kyc_status',
                                'latitude',
                                'longitude',
                                'pincode',
                                'city',
                                'state'
                            );
                        },
                    ]);
                },
                'lines' => function ($q) {
                    $q->select(
                        'id',
                        'item_reference_id',
                        'order_id',
                        'parent_line_id',
                        'is_replacement',
                        'product_id',
                        'variant_id',
                        'quantity',
                        'shipping_charge',
                        'returned_quantity',
                        'delivery_status',
                        'is_cancel_return_allowed',
                        'cancellation_requested_at',
                        'cancellation_rejected_at',
                        'cancelled_at',
                        'cancellation_reason',
                        'dispatched_at',
                        'return_status',
                        'unit_price',
                        'gst_rate',
                        'cgst_rate',
                        'sgst_rate',
                        'igst_rate',
                        'gst_amount',
                        'cgst_amount',
                        'sgst_amount',
                        'igst_amount',
                        'line_total',
                        'buyback_requested_at',
                        'buyback_approved_at',
                        'buyback_rejected_at',
                        'buyback_refunded_at',
                        'tax_data',
                        'commissionable_volume',
                        'delivered_at',
                        'shipped_at',
                        'return_requested_at',
                        'return_approved_at',
                        'return_rejected_at',
                        'return_completed_at',
                        'return_reason',
                        'return_rejection_reason',
                        'created_at'
                    )->orderBy('id');
                },
            ])
            ->select([
                'id',
                'is_replacement',
                'order_reference',
                'user_id',
                'order_type',
                'subtotal',
                'total_gst',
                'total_cgst',
                'total_sgst',
                'total_igst',
                'shipping_charge',
                'coin_redeemed',
                'coin_redeemed_amount',
                'total_payable',
                'amount_paid',
                'status',
                'courier_company',
                'courier_tracking_number',
                'payment_gateway',
                'gateway_transaction_id',
                'checkout_type',
                'tax_breakdown',
                'coupon_discount',
                'coupon_code',
                'created_at',
            ])
            ->orderBy('id'); // chunkById ke liye consistent

        // ---------- Filters (optional) ----------
        if ($request->filled('from'))            $query->whereDate('created_at', '>=', $request->date('from'));
        if ($request->filled('to'))              $query->whereDate('created_at', '<=', $request->date('to'));
        if ($request->filled('status'))          $query->where('status', $request->string('status'));
        if ($request->filled('delivery_status')) $query->where('delivery_status', $request->string('delivery_status'));
        if ($request->filled('payment_gateway')) $query->where('payment_gateway', $request->string('payment_gateway'));
        if ($request->filled('user_id'))         $query->where('user_id', $request->integer('user_id'));
        if ($request->boolean('paid_only'))      $query->whereNotNull('gateway_transaction_id');

        $filename = 'order-details-' . now()->format('Y-m-d_His') . '.csv';

        return $this->csv->streamGrouped(
            $filename,
            $query,
            function (Order $order) {
                $rows = [];

                // ============ ORDER SECTION ============
                $rows[] = ['--- ORDER DETAILS ---'];
                $rows[] = ['id',                  $order->id];
                $rows[] = ['is_replacement',      $this->bool($order->is_replacement)];
                $rows[] = ['order_reference',     $order->order_reference];
                $rows[] = ['user_id',             $order->user_id];

                $rows[] = ['order_type',          $order->order_type];
                $rows[] = ['subtotal',            $this->money($order->subtotal)];
                $rows[] = ['total_gst',           $this->money($order->total_gst)];
                $rows[] = ['total_cgst',          $this->money($order->total_cgst)];
                $rows[] = ['total_sgst',          $this->money($order->total_sgst)];
                $rows[] = ['total_igst',          $this->money($order->total_igst)];
                $rows[] = ['shipping_charge',     $this->money($order->shipping_charge)];
                $rows[] = ['coin_redeemed',       $order->coin_redeemed];
                $rows[] = ['coin_redeemed_amount', $this->money($order->coin_redeemed_amount)];
                $rows[] = ['total_payable',       $this->money($order->total_payable)];
                $rows[] = ['amount_paid',         $this->money($order->amount_paid)];
                $rows[] = ['status',              $order->status];
                $rows[] = ['courier_company',     $order->courier_company];
                $rows[] = ['courier_tracking_number', $order->courier_tracking_number];
                $rows[] = ['payment_gateway',     $order->payment_gateway];
                $rows[] = ['gateway_transaction_id', $order->gateway_transaction_id];
                $rows[] = ['checkout_type',       $order->checkout_type];
                $rows[] = ['tax_breakdown',       $this->stringify($order->tax_breakdown)];
                $rows[] = ['coupon_discount',     $this->money($order->coupon_discount)];
                $rows[] = ['coupon_code',         $order->coupon_code];
                $rows[] = ['created_at',          optional($order->created_at)->toDateTimeString()];

                // ============ CUSTOMER SECTION ============
                $rows[] = [];
                $rows[] = ['--- CUSTOMER DETAILS ---'];
                $rows[] = ['full_name',    $order->user?->full_name];
                $rows[] = ['email',        $order->user?->email];
                $rows[] = ['phone',        $order->user?->phone];
                $rows[] = ['account_type', $order->user?->account_type];

                // Distributor details (only if account_type == distributor)
                if ($order->user && strtolower((string)$order->user->account_type) === 'distributor') {
                    $dp = $order->user->distributorProfile;

                    $rows[] = [];
                    $rows[] = ['--- DISTRIBUTOR PROFILE ---'];
                    $rows[] = ['gst_in',                $dp?->gst_in];
                    $rows[] = ['company_name',          $dp?->company_name];
                    $rows[] = ['encrypted_pan',         $dp?->encrypted_pan];
                    $rows[] = ['encrypted_bank_account', $dp?->encrypted_bank_account];
                    $rows[] = ['bank_ifsc',             $dp?->bank_ifsc];
                    $rows[] = ['kyc_status',            $dp?->kyc_status];
                    $rows[] = ['latitude',              $dp?->latitude];
                    $rows[] = ['longitude',             $dp?->longitude];
                    $rows[] = ['pincode',               $dp?->pincode];
                    $rows[] = ['city',                  $dp?->city];
                    $rows[] = ['state',                 $dp?->state];
                }

                // ============ ITEMS SECTION ============
                $rows[] = [];
                $rows[] = ['--- ORDER LINES ---'];
                $rows[] = [
                    'id',
                    'item_reference_id',
                    'order_id',
                    'parent_line_id',
                    'is_replacement',
                    'product_id',
                    'variant_id',
                    'quantity',
                    'shipping_charge',
                    'returned_quantity',
                    'delivery_status',
                    'is_cancel_return_allowed',
                    'cancellation_requested_at',
                    'cancellation_rejected_at',
                    'cancelled_at',
                    'cancellation_reason',
                    'dispatched_at',
                    'return_status',
                    'unit_price',
                    'gst_rate',
                    'cgst_rate',
                    'sgst_rate',
                    'igst_rate',
                    'gst_amount',
                    'cgst_amount',
                    'sgst_amount',
                    'igst_amount',
                    'line_total',
                    'buyback_requested_at',
                    'buyback_approved_at',
                    'buyback_rejected_at',
                    'buyback_refunded_at',
                    'tax_data',
                    'commissionable_volume',
                    'delivered_at',
                    'shipped_at',
                    'return_requested_at',
                    'return_approved_at',
                    'return_rejected_at',
                    'return_completed_at',
                    'return_reason',
                    'return_rejection_reason',
                    'created_at',
                ];

                foreach ($order->lines as $line) {
                    $rows[] = [
                        $line->id,
                        $line->item_reference_id,
                        $line->order_id,
                        $line->parent_line_id,
                        $this->bool($line->is_replacement),
                        $line->product_id,
                        $line->variant_id,
                        $line->quantity,
                        $this->money($line->shipping_charge),
                        $line->returned_quantity,
                        $line->delivery_status,
                        $this->bool($line->is_cancel_return_allowed),
                        optional($line->cancellation_requested_at)->toDateTimeString(),
                        optional($line->cancellation_rejected_at)->toDateTimeString(),
                        optional($line->cancelled_at)->toDateTimeString(),
                        $line->cancellation_reason,
                        optional($line->dispatched_at)->toDateTimeString(),
                        $line->return_status,
                        $this->money($line->unit_price),
                        $line->gst_rate,
                        $line->cgst_rate,
                        $line->sgst_rate,
                        $line->igst_rate,
                        $this->money($line->gst_amount),
                        $this->money($line->cgst_amount),
                        $this->money($line->sgst_amount),
                        $this->money($line->igst_amount),
                        $this->money($line->line_total),
                        optional($line->buyback_requested_at)->toDateTimeString(),
                        optional($line->buyback_approved_at)->toDateTimeString(),
                        optional($line->buyback_rejected_at)->toDateTimeString(),
                        optional($line->buyback_refunded_at)->toDateTimeString(),
                        $this->stringify($line->tax_data),
                        $line->commissionable_volume,
                        optional($line->delivered_at)->toDateTimeString(),
                        optional($line->shipped_at)->toDateTimeString(),
                        optional($line->return_requested_at)->toDateTimeString(),
                        optional($line->return_approved_at)->toDateTimeString(),
                        optional($line->return_rejected_at)->toDateTimeString(),
                        optional($line->return_completed_at)->toDateTimeString(),
                        $line->return_reason,
                        $line->return_rejection_reason,
                        optional($line->created_at)->toDateTimeString(),
                    ];
                }

                // Separator between orders
                $rows[] = [];
                $rows[] = ['========================================'];
                $rows[] = [];

                return $rows;
            }
        );
    }

    private function bool($v): string
    {
        return $v ? 'Yes' : 'No';
    }

    private function money($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function stringify($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) ($value ?? '');
    }
}
