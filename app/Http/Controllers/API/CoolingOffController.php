<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\AdminNotification;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoolingOffController extends Controller
{
    /**
     * Initiate cooling-off withdrawal for an order.
     * POST /api/orders/{orderReference}/cooling-off-withdraw
     * POST /api/distributor/orders/{orderReference}/cooling-off-withdraw
     *
     * 30 days from date of purchase (NOT delivery).
     * FRD: FR-CO-013 — no reason required, full order only.
     */
    public function withdraw(Request $request, string $orderReference): JsonResponse
    {
        $user = Auth::user();

        // 1. Find the order
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $user->id)
            ->with(['lines.product'])
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or does not belong to you.',
            ], 404);
        }

        // 2. Cancelled / returned orders cannot be cooled off
        if (in_array($order->status, ['cancelled', 'returned'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This order is already cancelled or returned.',
            ], 422);
        }

        // 3. Cooling-off window from PURCHASE date
        $coolingOffDays    = (int) setting('cooling_off_days', 30);
        $daysSincePurchase = $order->created_at->diffInDays(now());

        if ($daysSincePurchase > $coolingOffDays) {
            return response()->json([
                'success' => false,
                'message' => "Cooling-off period has expired. You can only withdraw within {$coolingOffDays} days of purchase. ({$daysSincePurchase} days passed)",
            ], 422);
        }

        // 4. Existing return / cooling-off in progress
        if ($order->hasPendingReturn() || $order->hasApprovedReturn()) {
            return response()->json([
                'success' => false,
                'message' => 'A return or withdrawal request is already in progress for this order.',
            ], 422);
        }

        // 5. Any line already in a return state blocks full-order cooling-off
        $hasReturnedItems = $order->lines->contains(function ($line) {
            return in_array($line->return_status, ['returned', 'approved', 'pending'], true);
        });

        if ($hasReturnedItems) {
            return response()->json([
                'success' => false,
                'message' => 'Some items in this order have already been returned. Cooling-off withdrawal requires the entire order.',
            ], 422);
        }

        // 6. Prepare return items (ALL items in the order)
        $returnItems      = [];
        $totalRefund      = 0;
        $totalTax         = 0;
        $totalCvReversed  = 0;
        $processedLineIds = [];

        foreach ($order->lines as $line) {
            $availableQty = $line->quantity - ($line->returned_quantity ?? 0);
            if ($availableQty <= 0) {
                continue;
            }

            $perUnitLineTotal = (float) $line->line_total / $line->quantity;
            $perUnitTax       = (float) ($line->gst_amount ?? 0) / $line->quantity;

            $itemTotal = round($perUnitLineTotal * $availableQty, 2);
            $itemTax   = round($perUnitTax * $availableQty, 2);

            $cvPerUnit  = (float) ($line->commissionable_volume ?? 0) / $line->quantity;
            $cvReversed = $cvPerUnit * $availableQty;

            $returnItems[] = [
                'order_line_id' => $line->id,
                'product_id'    => $line->product_id,
                'product_name'  => $line->product->name ?? 'Unknown',
                'quantity'      => $availableQty,
                'unit_price'    => (float) $line->unit_price,
                'gst_rate'      => (float) $line->gst_rate,
                'subtotal'      => round($itemTotal - $itemTax, 2),
                'tax'           => $itemTax,
                'line_total'    => $itemTotal,
                'reason'        => null,   // FRD: no reason
                'image_paths'   => [],
                'return_status' => 'pending',
            ];

            $totalRefund     += $itemTotal;
            $totalTax        += $itemTax;
            $totalCvReversed += $cvReversed;
            $processedLineIds[] = $line->id;
        }

        if (empty($returnItems)) {
            return response()->json([
                'success' => false,
                'message' => 'No items available for return in this order.',
            ], 422);
        }

        // 7. Persist
        DB::beginTransaction();

        try {
            $returnOrder = OrderReturn::create([
                'order_id'            => $order->id,
                'user_id'             => $user->id,
                'type'                => 'cooling_off',
                'items'               => $returnItems,
                'status'              => 'pending',
                'reason'              => null,   // FRD: no reason required
                'refund_subtotal'     => round($totalRefund - $totalTax, 2),
                'refund_tax'          => $totalTax,
                'refund_shipping'     => 0,
                'total_refund_amount' => $totalRefund,
                'total_cv_reversed'   => $totalCvReversed,
                'extra_data'          => [
                    'cooling_off_days'        => $coolingOffDays,
                    'days_since_purchase'     => $daysSincePurchase,
                    'withdrawal_initiated_at' => now()->toDateTimeString(),
                    'initiated_by'            => 'customer',
                ],
            ]);

            // Update order lines
            foreach ($returnItems as $item) {
                $orderLine = OrderLine::find($item['order_line_id']);
                if ($orderLine) {
                    $orderLine->update([
                        'return_status'        => 'pending',
                        'return_requested_at'  => now(),
                        'returned_quantity'    => ($orderLine->returned_quantity ?? 0) + $item['quantity'],
                    ]);
                }
            }

            // Update order return status
            $order->update([
                'return_status' => 'pending',
            ]);

            // Admin notification
            $this->createCoolingOffNotification($returnOrder);

            // Audit log (FR-XC-003)
            AuditLog::create([
                'user_id'    => $user->id,
                'action'     => 'cooling_off_withdraw_initiated',
                'module'     => 'returns',
                'old_values' => null,
                'new_values' => json_encode([
                    'return_id'       => $returnOrder->id,
                    'order_reference' => $order->order_reference,
                    'total_refund'    => $totalRefund,
                    'items_count'     => count($returnItems),
                ]),
                'ip_address' => $request->ip(),
            ]);

            DB::commit();

            Log::info('Cooling-off withdrawal initiated', [
                'return_id'       => $returnOrder->id,
                'order_reference' => $order->order_reference,
                'user_id'         => $user->id,
                'total_refund'    => $totalRefund,
                'items_count'     => count($returnItems),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cooling-off withdrawal initiated successfully. Admin will review and process your refund within 5-7 business days.',
                'data'    => [
                    'return_id'       => $returnOrder->id,
                    'order_reference' => $order->order_reference,
                    'status'          => 'pending',
                    'refund_amount'   => $totalRefund,
                    'items_count'     => count($returnItems),
                    'remaining_days'  => max(0, $coolingOffDays - $daysSincePurchase),
                    'expiry_date'     => $order->created_at->copy()->addDays($coolingOffDays)->toDateString(),
                    'created_at'      => $returnOrder->created_at->toDateTimeString(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Cooling-off withdrawal failed', [
                'order_reference' => $order->order_reference,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate cooling-off withdrawal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cooling-off eligibility for an order.
     * GET /api/orders/{orderReference}/cooling-off-eligibility
     * GET /api/distributor/orders/{orderReference}/cooling-off-eligibility
     */
    public function eligibility(Request $request, string $orderReference): JsonResponse
    {
        $user = Auth::user();

        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $coolingOffDays    = (int) setting('cooling_off_days', 30);
        $daysSincePurchase = $order->created_at->diffInDays(now());

        $isEligible = $daysSincePurchase <= $coolingOffDays
            && !$order->hasPendingReturn()
            && !$order->hasApprovedReturn()
            && !in_array($order->status, ['cancelled', 'returned'], true);

        return response()->json([
            'success' => true,
            'data'    => [
                'order_reference'     => $order->order_reference,
                'is_eligible'         => $isEligible,
                'cooling_off_days'    => $coolingOffDays,
                'days_since_purchase' => $daysSincePurchase,
                'remaining_days'      => max(0, $coolingOffDays - $daysSincePurchase),
                'expiry_date'         => $order->created_at->copy()->addDays($coolingOffDays)->toDateString(),
                'order_status'        => $order->status,
                'has_pending_return'  => $order->hasPendingReturn(),
                'has_approved_return' => $order->hasApprovedReturn(),
                'reason_required'     => false,
                'scope'               => 'full_order_only',
            ],
        ]);
    }

    /**
     * Check distributorship cooling-off eligibility.
     * GET /api/distributor/cooling-off/eligibility
     *
     * FRD: FR-CO-013 — 30 days from approval, no reason required.
     */
    public function distributorshipEligibility(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $profile = $user->distributorProfile;

        if (!$profile || $profile->application_status !== 'approved') {
            return response()->json([
                'success' => true,
                'data'    => [
                    'is_eligible' => false,
                    'reason'      => 'No active distributorship found.',
                ],
            ]);
        }

        $coolingOffDays = (int) setting('cooling_off_days', 30);
        $approvedAt     = $profile->reviewed_at ?? $profile->updated_at;
        $daysSince      = $approvedAt->diffInDays(now());
        $isEligible     = $daysSince <= $coolingOffDays;

        return response()->json([
            'success' => true,
            'data'    => [
                'is_eligible'         => $isEligible,
                'distributor_id'      => $user->distributor_id ?? null,
                'application_status'  => $profile->application_status,
                'approved_at'         => $approvedAt->toDateTimeString(),
                'cooling_off_days'    => $coolingOffDays,
                'days_since_approval' => $daysSince,
                'remaining_days'      => max(0, $coolingOffDays - $daysSince),
                'expiry_date'         => $approvedAt->copy()->addDays($coolingOffDays)->toDateString(),
                'reason_required'     => false,
            ],
        ]);
    }

    /**
     * Withdraw from distributorship under cooling-off.
     * POST /api/distributor/cooling-off/withdraw-distributorship
     *
     * FRD: FR-CO-013 — no reason, account-level, returns.order_id = NULL.
     */
    public function withdrawDistributorship(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $profile = $user->distributorProfile;

        if (!$profile || $profile->application_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Active distributorship not found.',
            ], 422);
        }

        $coolingOffDays = (int) setting('cooling_off_days', 30);
        $approvedAt     = $profile->reviewed_at ?? $profile->updated_at;
        $daysSince      = $approvedAt->diffInDays(now());

        if ($daysSince > $coolingOffDays) {
            return response()->json([
                'success' => false,
                'message' => "Cooling-off period has expired ({$daysSince} days since approval).",
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Qualifying purchases within the cooling-off window for reversal
            $qualifyingOrders = Order::where('user_id', $user->id)
                ->where('order_type', 'distributor')
                ->where('status', 'confirmed')
                ->whereBetween('created_at', [
                    $approvedAt,
                    $approvedAt->copy()->addDays($coolingOffDays),
                ])
                ->get();

            $totalCv = (float) $qualifyingOrders->sum('commissionable_volume');

            $returnOrder = OrderReturn::create([
                'order_id'            => null, // account-level
                'user_id'             => $user->id,
                'type'                => 'cooling_off',
                'items'               => [],
                'status'              => 'pending',
                'reason'              => null, // FRD: no reason
                'refund_subtotal'     => 0,
                'refund_tax'          => 0,
                'refund_shipping'     => 0,
                'total_refund_amount' => 0,
                'total_cv_reversed'   => $totalCv,
                'extra_data'          => [
                    'scope'             => 'distributorship_withdrawal',
                    'distributor_id'    => $user->distributor_id ?? null,
                    'withdrawn_at'      => now()->toDateTimeString(),
                    'qualifying_orders' => $qualifyingOrders->pluck('order_reference')->toArray(),
                    'initiated_by'      => 'distributor',
                ],
            ]);

            $profile->update([
                'application_status' => 'withdrawn',
                'withdrawn_at'       => now(),
            ]);

            // Admin notification
            AdminNotification::create([
                'admin_id'       => null,
                'type'           => 'cooling_off_pending',
                'title'          => 'Distributor Distributorship Withdrawal',
                'message'        => "Distributor {$user->name} withdrew under cooling-off.",
                'reference_type' => 'return',
                'reference_id'   => $returnOrder->id,
                'priority'       => 'high',
                'extra_data'     => json_encode([
                    'user_id'    => $user->id,
                    'return_id'  => $returnOrder->id,
                    'type'       => 'distributorship_withdrawal',
                    'cv_reversed'=> $totalCv,
                ]),
            ]);

            // Audit log (FR-XC-003)
            AuditLog::create([
                'user_id'    => $user->id,
                'action'     => 'distributorship_withdrawn',
                'module'     => 'distributor',
                'old_values' => json_encode(['application_status' => 'approved']),
                'new_values' => json_encode(['application_status' => 'withdrawn']),
                'ip_address' => $request->ip(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Distributorship withdrawn under cooling-off. No reason required.',
                'data'    => [
                    'return_id'      => $returnOrder->id,
                    'status'         => 'pending',
                    'withdrawn_at'   => now()->toDateTimeString(),
                    'cv_to_reverse'  => $totalCv,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Distributorship withdrawal failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to withdraw distributorship.',
            ], 500);
        }
    }

    /**
     * Get history of cooling-off withdrawals for the authenticated user.
     * GET /api/cooling-off/history
     * GET /api/distributor/cooling-off/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = Auth::user();

        $returns = OrderReturn::where('user_id', $user->id)
            ->where('type', 'cooling_off')
            ->with('order')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $returns->map(function ($return) {
                $extraData = is_array($return->extra_data)
                    ? $return->extra_data
                    : json_decode($return->extra_data ?? '{}', true);

                return [
                    'id'                  => $return->id,
                    'order_reference'     => $return->order->order_reference ?? null,
                    'scope'               => $extraData['scope'] ?? 'order',
                    'status'              => $return->status,
                    'items_count'         => is_array($return->items) ? count($return->items) : 0,
                    'total_refund'        => (float) $return->total_refund_amount,
                    'total_cv_reversed'   => (float) $return->total_cv_reversed,
                    'reason'              => $return->reason,
                    'admin_notes'         => $return->admin_notes,
                    'rejection_reason'    => $return->rejection_reason,
                    'created_at'          => $return->created_at?->toDateTimeString(),
                    'approved_at'         => $return->approved_at?->toDateTimeString(),
                    'completed_at'        => $return->completed_at?->toDateTimeString(),
                    'refund_processed_at' => $return->refund_processed_at?->toDateTimeString(),
                ];
            }),
            'total' => $returns->count(),
        ]);
    }

    /**
     * Create admin notification for cooling-off withdrawal.
     */
    protected function createCoolingOffNotification(OrderReturn $returnOrder): void
    {
        AdminNotification::create([
            'admin_id'       => null,
            'type'           => 'cooling_off_pending',
            'title'          => 'New Cooling-Off Withdrawal Request',
            'message'        => "Cooling-off withdrawal for Order #{$returnOrder->order->order_reference} has been initiated. Total refund: ₹{$returnOrder->total_refund_amount}",
            'reference_type' => 'return',
            'reference_id'   => $returnOrder->id,
            'priority'       => 'high',
            'extra_data'     => json_encode([
                'order_reference' => $returnOrder->order->order_reference ?? null,
                'user_id'         => $returnOrder->user_id,
                'total_refund'    => (float) $returnOrder->total_refund_amount,
                'items_count'     => is_array($returnOrder->items) ? count($returnOrder->items) : 0,
                'status'          => $returnOrder->status,
                'return_id'       => $returnOrder->id,
                'type'            => 'cooling_off',
            ]),
        ]);
    }
}