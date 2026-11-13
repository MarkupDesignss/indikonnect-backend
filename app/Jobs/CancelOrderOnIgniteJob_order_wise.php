<?php

namespace App\Jobs;

use App\Models\Order;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CancelOrderOnIgniteJob_order_wise implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;
    public int $timeout = 60;

    /** Exponential backoff — covers the Saturday settlement window (~1h). */
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(public int $orderId)   // ← orderId, not orderLineId
    {
        $this->onQueue('ignite-sync');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }

    public function handle(IgniteClient $ignite): void
    {
        $order = Order::with(['user', 'lines'])->find($this->orderId);

        if (! $order) {
            Log::warning('Ignite cancel: order not found', ['order_id' => $this->orderId]);
            return;
        }

        // Order was never posted to Ignite — nothing to cancel there
        if (empty($order->ignite_order_no)) {
            Log::info('Ignite cancel: order never posted, skipping', [
                'order_id' => $order->id,
            ]);
            return;
        }

        // Already cancelled on Ignite
        if ($order->ignite_sync_status === 'cancelled') {
            Log::info('Ignite cancel: already cancelled', ['order_id' => $order->id]);
            return;
        }

        // ORDER-level ref — MUST match PostOrderToIgnitecopyJob
        $partnerRef = $order->order_reference;

        try {
            $response = $ignite->cancelOrder($partnerRef);
        } catch (\Throwable $e) {
            Log::warning('Ignite cancelOrder transport error, retrying', [
                'order_id' => $order->id,
                'ref'      => $partnerRef,
                'error'    => $e->getMessage(),
            ]);
            $this->release(30);
            return;
        }

        $code = $response['code'] ?? null;

        // ── Success (idempotent) ─────────────────────────────────────
        if ($code === ResultCode::OK->value
            || $code === ResultCode::ORDER_ALREADY_CANCELLED->value) {

            $order->update([
                'ignite_sync_status' => 'cancelled',
                'ignite_sync_error'  => null,
            ]);

            // Mark all lines cancelled too (for reporting consistency)
            $order->lines()->update([
                'ignite_sync_status' => 'cancelled',
                'ignite_sync_error'  => null,
            ]);

            $this->forgetMemberCache($order);

            Log::info('Ignite order cancelled', [
                'order_id' => $order->id,
                'ref'      => $partnerRef,
                'code'     => $code,
            ]);
            return;
        }

        // ── Settlement running — retry ───────────────────────────────
        if ($code === ResultCode::NETWORK_BUSY->value) {
            Log::info('Ignite cancel: settlement in progress, retrying', [
                'order_id' => $order->id,
            ]);
            $this->release(30);
            return;
        }

        // ── Order never landed on Ignite — permanent fail ────────────
        if ($code === ResultCode::ORDER_NOT_FOUND->value) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "ORDER_NOT_FOUND: ref={$partnerRef}",
            ]);

            Log::error('Ignite cancel: ORDER_NOT_FOUND — partner_ref mismatch or createOrder never landed', [
                'order_id'        => $order->id,
                'ref'             => $partnerRef,
                'ignite_order_no' => $order->ignite_order_no,
            ]);
            return;
        }

        // ── Any other business refusal — permanent fail ──────────────
        $order->update([
            'ignite_sync_status' => 'failed',
            'ignite_sync_error'  => "[{$code}] " . ($response['msg'] ?? 'unknown'),
        ]);

        Log::error('Ignite cancel: non-retryable refusal', [
            'order_id' => $order->id,
            'ref'      => $partnerRef,
            'code'     => $code,
            'msg'      => $response['msg'] ?? null,
        ]);
    }

    /**
     * Clear the member cache so subsequent reads see fresh balances/CV.
     */
    protected function forgetMemberCache(Order $order): void
    {
        $user = $order->user;

        if ($user && ! empty($user->distributor_id)) {
            $mainBaId = preg_replace('/-(002|003)$/', '-001', $user->distributor_id);
            Cache::forget("ignite_member_{$mainBaId}");
            Cache::forget("ignite_binary_tree_{$mainBaId}");
        }
    }
}