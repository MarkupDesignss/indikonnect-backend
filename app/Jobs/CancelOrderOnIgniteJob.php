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

class CancelOrderOnIgniteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 200;
    public int $timeout = 60;

    public array $backoff = [30, 60, 120, 300, 600];

    private const PERMANENT_CODES = [
        ResultCode::VALIDATION_FAILED->value,
        ResultCode::MEMBER_NOT_FOUND->value,
    ];

    public function __construct(public int $orderId)
    {
        $this->onQueue('ignite-sync');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function handle(IgniteClient $ignite): void
    {
        $order = Order::with(['user', 'lines'])->find($this->orderId);

        if (!$order) {
            Log::warning('Ignite cancel: order not found', ['order_id' => $this->orderId]);
            return;
        }

        if (empty($order->ignite_order_no)) {
            Log::info('Ignite cancel: order never posted, skipping', ['order_id' => $order->id]);
            return;
        }

        if ($order->ignite_sync_status === 'cancelled') {
            Log::info('Ignite cancel: already cancelled', ['order_id' => $order->id]);
            return;
        }

        $partnerRef = $order->order_reference;
        if (empty($partnerRef)) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => 'order_reference is empty; cannot cancel on Ignite.',
            ]);
            Log::error('Ignite cancel: empty order_reference', ['order_id' => $order->id]);
            return;
        }

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
        $msg  = $response['msg'] ?? 'unknown';

        // Success (idempotent)
        if ($code === ResultCode::OK->value
            || $code === ResultCode::ORDER_ALREADY_CANCELLED->value) {

            $order->update([
                'ignite_sync_status' => 'cancelled',
                'ignite_sync_error'  => null,
            ]);

            $order->lines()->update([
                'ignite_sync_status' => 'cancelled',
                'ignite_sync_error'  => null,
                'ignite_cv_posted'   => 0,
            ]);

            $this->forgetMemberCache($order);

            Log::info('Ignite order cancelled', [
                'order_id' => $order->id,
                'ref'      => $partnerRef,
                'code'     => $code,
            ]);
            return;
        }

        // Settlement running — retry
        if ($code === ResultCode::NETWORK_BUSY->value) {
            Log::info('Ignite cancel: NETWORK_BUSY, releasing 30s', ['order_id' => $order->id]);
            $this->release(30);
            return;
        }

        // ORDER_NOT_FOUND may race with PostOrder — retry until exhausted
        if ($code === ResultCode::ORDER_NOT_FOUND->value) {
            if ($this->attempts() < $this->tries && $this->hasTimeLeft()) {
                Log::info('Ignite cancel: ORDER_NOT_FOUND, retrying in 5 min', [
                    'order_id' => $order->id,
                    'attempt'  => $this->attempts(),
                ]);
                $this->release(300);
                return;
            }

            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "ORDER_NOT_FOUND after {$this->attempts()} attempts: ref={$partnerRef}",
            ]);
            Log::error('Ignite cancel: ORDER_NOT_FOUND exhausted retries', [
                'order_id'        => $order->id,
                'ref'             => $partnerRef,
                'ignite_order_no' => $order->ignite_order_no,
            ]);
            return;
        }

        // Permanent
        if (in_array($code, self::PERMANENT_CODES, true)) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "[{$code}] {$msg}",
            ]);
            Log::error('Ignite cancel: permanent refusal', [
                'order_id' => $order->id,
                'code'     => $code,
                'msg'      => $msg,
            ]);
            return;
        }

        // Any other refusal — fail (cancel has no other expected code)
        $order->update([
            'ignite_sync_status' => 'failed',
            'ignite_sync_error'  => "[{$code}] {$msg}",
        ]);
        Log::error('Ignite cancel: non-retryable refusal', [
            'order_id' => $order->id,
            'ref'      => $partnerRef,
            'code'     => $code,
            'msg'      => $msg,
        ]);
    }

    private function hasTimeLeft(): bool
    {
        return now()->lt($this->retryUntil());
    }

    protected function forgetMemberCache(Order $order): void
    {
        $user = $order->user;
        if ($user && !empty($user->distributor_id)) {
            $mainBaId = preg_replace('/-(002|003)$/', '-001', $user->distributor_id);
            Cache::forget("ignite_member_{$mainBaId}");
            Cache::forget("ignite_binary_tree_{$mainBaId}");
        }
    }
}