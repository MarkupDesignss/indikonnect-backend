<?php

namespace App\Services;

use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancellationService
{
    /**
     * Request cancellation (pending admin approval)
     */
    public function requestCancellation(
        int $userId,
        string $orderReference,
        int $orderLineId,
        string $reason
    ): array {
        // Find the specific order line
        $orderLine = OrderLine::where('id', $orderLineId)
            ->whereHas('order', function ($query) use ($userId, $orderReference) {
                $query->where('order_reference', $orderReference)
                    ->where('user_id', $userId);
            })
            ->with(['order', 'product', 'variant'])
            ->firstOrFail();

        $order = $orderLine->order;

        // Check if line is already cancelled or pending
        if (in_array($orderLine->delivery_status, ['cancelled', 'cancel_pending', 'cancel_rejected'])) {
            throw new \Exception('This item cannot be cancelled');
        }

        // Check if item can be cancelled
        if (in_array($orderLine->delivery_status, ['delivered', 'shipped'])) {
            throw new \Exception('Items that are shipped or delivered cannot be cancelled');
        }

        // Update order line status to pending cancellation
        $orderLine->update([
            'delivery_status' => 'cancel_pending',
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        Log::info('Cancellation request created', [
            'order' => $orderReference,
            'order_line_id' => $orderLine->id,
            'user_id' => $userId,
            'reason' => $reason,
        ]);

        /* =============================================
        | SEND CANCELLATION REQUESTED SMS (TWILIO)
        ============================================= */
        $phone = $order->user?->phone;

        if ($phone) {
            $productName = $orderLine->product->name ?? 'your item';
            $message = "Hi {$order->user->name}! Your cancellation request for "
                . "'{$productName}' in order {$orderReference} has been submitted. "
                . "You will be notified once it is reviewed.";

            try {
                app(\App\Services\TwilioSmsService::class)->send($phone, $message);
            } catch (\Throwable $e) {
                Log::error('Cancellation request SMS failed', [
                    'order_reference' => $orderReference,
                    'order_line_id'   => $orderLine->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return [
            'order_reference' => $orderReference,
            'order_line_id' => $orderLine->id,
            'line_status' => $orderLine->delivery_status,
            'order_status' => $orderLine->order->status,
            'reason' => $reason,
            'requested_at' => $orderLine->cancellation_requested_at,
            'status' => 'pending_approval',
        ];
    }
}
