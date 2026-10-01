<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function csvData(Request $request)
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
            ->orderByDesc('id');

        // ---------- Optional filters ----------
        if ($request->filled('from'))            $query->whereDate('created_at', '>=', $request->date('from'));
        if ($request->filled('to'))              $query->whereDate('created_at', '<=', $request->date('to'));
        if ($request->filled('status'))          $query->where('status', $request->string('status'));
        if ($request->filled('payment_gateway')) $query->where('payment_gateway', $request->string('payment_gateway'));
        if ($request->filled('user_id'))         $query->where('user_id', $request->integer('user_id'));
        if ($request->boolean('paid_only'))      $query->whereNotNull('gateway_transaction_id');

        $perPage = min((int) $request->input('per_page', 20), 100);
        $orders  = $query->paginate($perPage);

        $data = collect($orders->items())->map(function (Order $order) {
            return [
                'order'    => $this->formatOrder($order),
                'customer' => $this->formatCustomer($order),
                'items'    => $this->formatItems($order),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Formatters
    // ------------------------------------------------------------------

    private function formatOrder(Order $order): array
    {
        return [
            'id'                      => $order->id,
            'is_replacement'          => (bool) $order->is_replacement,
            'order_reference'         => $order->order_reference,
            'user_id'                 => $order->user_id,
            'order_type'              => $order->order_type,
            'subtotal'                => $this->money($order->subtotal),
            'total_gst'               => $this->money($order->total_gst),
            'total_cgst'              => $this->money($order->total_cgst),
            'total_sgst'              => $this->money($order->total_sgst),
            'total_igst'              => $this->money($order->total_igst),
            'shipping_charge'         => $this->money($order->shipping_charge),
            'coin_redeemed'           => $order->coin_redeemed,
            'coin_redeemed_amount'    => $this->money($order->coin_redeemed_amount),
            'total_payable'           => $this->money($order->total_payable),
            'amount_paid'             => $this->money($order->amount_paid),
            'status'                  => $order->status,
            'courier_company'         => $order->courier_company,
            'courier_tracking_number' => $order->courier_tracking_number,
            'payment_gateway'         => $order->payment_gateway,
            'gateway_transaction_id'  => $order->gateway_transaction_id,
            'checkout_type'           => $order->checkout_type,
            'tax_breakdown'           => $order->tax_breakdown,
            'coupon_discount'         => $this->money($order->coupon_discount),
            'coupon_code'             => $order->coupon_code,
            'created_at'              => optional($order->created_at)->toDateTimeString(),
        ];
    }

    private function formatCustomer(Order $order): array
    {
        $user = $order->user;

        if (! $user) {
            return [
                'id'                  => null,
                'full_name'           => null,
                'email'               => null,
                'phone'               => null,
                'account_type'        => null,
                'country'             => null,
                'distributor_profile' => null,
            ];
        }

        $distributor = null;

        if (strtolower((string) $user->account_type) === 'distributor') {
            $dp = $user->distributorProfile;

            $distributor = $dp ? [
                'gst_in'                 => $dp->gst_in,
                'company_name'           => $dp->company_name,
                'encrypted_pan'          => $dp->encrypted_pan,
                'encrypted_bank_account' => $dp->encrypted_bank_account,
                'bank_ifsc'              => $dp->bank_ifsc,
                'kyc_status'             => $dp->kyc_status,
                'latitude'               => $dp->latitude,
                'longitude'              => $dp->longitude,
                'pincode'                => $dp->pincode,
                'city'                   => $dp->city,
                'state'                  => $dp->state,
            ] : null;
        }

        return [
            'id'                  => $user->id,
            'full_name'           => $user->full_name,
            'email'               => $user->email,
            'phone'               => $user->phone,
            'account_type'        => $user->account_type,
            'country'             => $user->country,
            'distributor_profile' => $distributor,
        ];
    }

    private function formatItems(Order $order): array
    {
        return $order->lines->map(function ($line) {
            return [
                'id'                        => $line->id,
                'item_reference_id'         => $line->item_reference_id,
                'order_id'                  => $line->order_id,
                'parent_line_id'            => $line->parent_line_id,
                'is_replacement'            => (bool) $line->is_replacement,
                'product_id'                => $line->product_id,
                'variant_id'                => $line->variant_id,
                'quantity'                  => $line->quantity,
                'shipping_charge'           => $this->money($line->shipping_charge),
                'returned_quantity'         => $line->returned_quantity,
                'delivery_status'           => $line->delivery_status,
                'is_cancel_return_allowed'  => (bool) $line->is_cancel_return_allowed,
                'cancellation_requested_at' => optional($line->cancellation_requested_at)->toDateTimeString(),
                'cancellation_rejected_at'  => optional($line->cancellation_rejected_at)->toDateTimeString(),
                'cancelled_at'              => optional($line->cancelled_at)->toDateTimeString(),
                'cancellation_reason'       => $line->cancellation_reason,
                'dispatched_at'             => optional($line->dispatched_at)->toDateTimeString(),
                'return_status'             => $line->return_status,
                'unit_price'                => $this->money($line->unit_price),
                'gst_rate'                  => $line->gst_rate,
                'cgst_rate'                 => $line->cgst_rate,
                'sgst_rate'                 => $line->sgst_rate,
                'igst_rate'                 => $line->igst_rate,
                'gst_amount'                => $this->money($line->gst_amount),
                'cgst_amount'               => $this->money($line->cgst_amount),
                'sgst_amount'               => $this->money($line->sgst_amount),
                'igst_amount'               => $this->money($line->igst_amount),
                'line_total'                => $this->money($line->line_total),
                'buyback_requested_at'      => optional($line->buyback_requested_at)->toDateTimeString(),
                'buyback_approved_at'       => optional($line->buyback_approved_at)->toDateTimeString(),
                'buyback_rejected_at'       => optional($line->buyback_rejected_at)->toDateTimeString(),
                'buyback_refunded_at'       => optional($line->buyback_refunded_at)->toDateTimeString(),
                'tax_data'                  => $line->tax_data,
                'commissionable_volume'     => $line->commissionable_volume,
                'delivered_at'              => optional($line->delivered_at)->toDateTimeString(),
                'shipped_at'                => optional($line->shipped_at)->toDateTimeString(),
                'return_requested_at'       => optional($line->return_requested_at)->toDateTimeString(),
                'return_approved_at'        => optional($line->return_approved_at)->toDateTimeString(),
                'return_rejected_at'        => optional($line->return_rejected_at)->toDateTimeString(),
                'return_completed_at'       => optional($line->return_completed_at)->toDateTimeString(),
                'return_reason'             => $line->return_reason,
                'return_rejection_reason'   => $line->return_rejection_reason,
                'created_at'                => optional($line->created_at)->toDateTimeString(),
            ];
        })->values()->all();
    }

    private function money($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
