<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancellationService
{
    /**
     * Request cancellation (pending admin approval)
     */
    // public function requestCancellation(
    //     int $userId,
    //     string $orderReference,
    //     int $orderLineId,
    //     string $reason
    // ): array {
    //     // Find the specific order line
    //     $orderLine = OrderLine::where('id', $orderLineId)
    //         ->whereHas('order', function ($query) use ($userId, $orderReference) {
    //             $query->where('order_reference', $orderReference)
    //                 ->where('user_id', $userId);
    //         })
    //         ->with(['order', 'product', 'variant'])
    //         ->firstOrFail();

    //     $order = $orderLine->order;

    //     // Check if line is already cancelled or pending
    //     if (in_array($orderLine->delivery_status, ['cancelled', 'cancel_pending', 'cancel_rejected'])) {
    //         throw new \Exception('This item cannot be cancelled');
    //     }

    //     // Check if item can be cancelled
    //     if (in_array($orderLine->delivery_status, ['delivered', 'shipped'])) {
    //         throw new \Exception('Items that are shipped or delivered cannot be cancelled');
    //     }

    //     // Update order line status to pending cancellation
    //     $orderLine->update([
    //         'delivery_status' => 'cancel_pending',
    //         'cancellation_requested_at' => now(),
    //         'cancellation_reason' => $reason,
    //     ]);

    //     Log::info('Cancellation request created', [
    //         'order' => $orderReference,
    //         'order_line_id' => $orderLine->id,
    //         'user_id' => $userId,
    //         'reason' => $reason,
    //     ]);

    //     /* =============================================
    //     | SEND CANCELLATION REQUESTED SMS (TWILIO)
    //     ============================================= */
    //     $phone = $order->user?->phone;

    //     if ($phone) {
    //         $productName = $orderLine->product->name ?? 'your item';
    //         $message = "Hi {$order->user->name}! Your cancellation request for "
    //             . "'{$productName}' in order {$orderReference} has been submitted. "
    //             . "You will be notified once it is reviewed.";

    //         try {
    //             app(\App\Services\TwilioSmsService::class)->send($phone, $message);
    //         } catch (\Throwable $e) {
    //             Log::error('Cancellation request SMS failed', [
    //                 'order_reference' => $orderReference,
    //                 'order_line_id'   => $orderLine->id,
    //                 'error'           => $e->getMessage(),
    //             ]);
    //         }
    //     }

    //     return [
    //         'order_reference' => $orderReference,
    //         'order_line_id' => $orderLine->id,
    //         'line_status' => $orderLine->delivery_status,
    //         'order_status' => $orderLine->order->status,
    //         'reason' => $reason,
    //         'requested_at' => $orderLine->cancellation_requested_at,
    //         'status' => 'pending_approval',
    //     ];
    // }
    public function requestCancellation(
        int $userId,
        string $orderReference,
        string $reason
    ): array {
        return DB::transaction(function () use ($userId, $orderReference, $reason) {

            // Lock the order
            $order = Order::where('order_reference', $orderReference)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // Load all lines
            $order->load('lines.product', 'lines.variant');

            // ---- Order-level guards ----
            if (in_array($order->status, ['cancelled', 'cancel_pending', 'cancel_rejected'], true)) {
                throw new \Exception('This order cannot be cancelled');
            }

            // Mandatory product check (any line)
            $mandatoryProductId = Product::where('is_mandatory', 1)->value('id');
            if ($mandatoryProductId) {
                $mandatoryLine = $order->lines->firstWhere('product_id', $mandatoryProductId);
                if ($mandatoryLine) {
                    $name = $mandatoryLine->product->name ?? 'the mandatory product';
                    throw new \Exception(
                        "The item \"{$name}\" is a mandatory product and cannot be cancelled."
                    );
                }
            }

            // ---- Per-line eligibility ----
            // Cancel allowed only for: pending / confirmed / processing
            $cancelableStatuses = ['pending', 'confirmed', 'processing'];

            $cancelableLines = $order->lines->filter(
                fn($line) => in_array($line->delivery_status, $cancelableStatuses, true)
            );

            $blockedLines = $order->lines->reject(
                fn($line) => in_array($line->delivery_status, $cancelableStatuses, true)
            );

            // Full-order rule: agar koi bhi line dispatch/ship/deliver ho chuki hai
            // toh pura order cancel nahi hoga (partial cancel avoid karo)
            if ($blockedLines->isNotEmpty()) {
                $blockedNames = $blockedLines->map(
                    fn($l) => $l->product->name ?? "Line #{$l->id}"
                )->implode(', ');

                throw new \Exception(
                    "Cannot cancel the full order. The following item(s) are already dispatched/shipped/delivered/cancelled: {$blockedNames}"
                );
            }

            if ($cancelableLines->isEmpty()) {
                throw new \Exception('No cancellable items found in this order.');
            }

            // ---- Apply cancel_pending to all lines ----
            $now = now();
            $affected = [];

            foreach ($cancelableLines as $line) {
                $line->update([
                    'delivery_status'           => 'cancel_pending',
                    'cancellation_requested_at' => $now,
                    'cancellation_reason'       => $reason,
                ]);

                $affected[] = [
                    'order_line_id' => $line->id,
                    'product_name'  => $line->product->name ?? 'Unknown',
                    'quantity'      => $line->quantity,
                    'line_total'    => $line->line_total,
                ];
            }

            // Sync order status
            $order->updateOrderStatus();

            Log::info('Order-level cancellation request created', [
                'order'       => $orderReference,
                'user_id'     => $userId,
                'line_count'  => count($affected),
                'reason'      => $reason,
            ]);

            // ---- SMS (non-blocking) ----
            $phone = $order->user?->phone;
            if ($phone) {
                $itemCount = count($affected);
                $sms = "Hi {$order->user->name}! "
                    . "Your cancellation request for order {$orderReference} "
                    . "({$itemCount} item(s)) has been submitted. "
                    . "You will be notified once it is reviewed.";

                try {
                    app(\App\Services\TwilioSmsService::class)->send($phone, $sms);
                } catch (\Throwable $e) {
                    Log::error('Cancellation request SMS failed', [
                        'order_reference' => $orderReference,
                        'error'           => $e->getMessage(),
                    ]);
                }
            }

            return [
                'order_reference' => $orderReference,
                'order_status'    => $order->fresh()->status,
                'status'          => 'pending_approval',
                'reason'          => $reason,
                'requested_at'    => $now->toDateTimeString(),
                'affected_lines'  => $affected,
            ];
        });
    }
}
