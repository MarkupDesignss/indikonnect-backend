<?php

namespace App\Jobs;

use App\Models\OrderLine;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CancelOrderOnIgniteJob_order_line implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;
    public int $timeout = 60;

    /** Exponential backoff — covers the Saturday settlement window (~1h). */
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(public int $orderLineId)
    {
        $this->onQueue('ignite-sync');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }

    public function handle(IgniteClient $ignite): void
    {
        $line = OrderLine::with('order')->find($this->orderLineId);

        if (! $line) {
            Log::warning('Ignite cancel: line not found', ['line_id' => $this->orderLineId]);
            return;
        }

        // Line was never posted to Ignite — nothing to cancel there
        if (empty($line->ignite_order_no)) {
            Log::info('Ignite cancel: no ignite_order_no on line, skipping', [
                'line_id' => $line->id,
            ]);
            return;
        }

        // Already cancelled on Ignite
        if ($line->ignite_sync_status === 'cancelled') {
            Log::info('Ignite cancel: already cancelled', ['line_id' => $line->id]);
            return;
        }

        $order = $line->order;

        if (! $order) {
            Log::warning('Ignite cancel: parent order missing', ['line_id' => $line->id]);
            return;
        }

        // SAME ref builder as PostOrderToIgniteJob
        $partnerRef = $this->buildPartnerRef($order->order_reference, $line->id);

        try {
            $response = $ignite->cancelOrder($partnerRef);
        } catch (\Throwable $e) {
            Log::warning('Ignite cancelOrder transport error, retrying', [
                'line_id' => $line->id,
                'ref'     => $partnerRef,
                'error'   => $e->getMessage(),
            ]);
            $this->release(30);
            return;
        }

        $code = $response['code'] ?? null;

        // ── Success (idempotent) ─────────────────────────────────────
        if ($code === ResultCode::OK->value
            || $code === ResultCode::ORDER_ALREADY_CANCELLED->value) {

            $line->update([
                'ignite_sync_status' => 'cancelled',
                'ignite_sync_error'  => null,
            ]);

            $this->forgetMemberCache($order, $line);

            Log::info('Ignite line cancelled', [
                'line_id' => $line->id,
                'ref'     => $partnerRef,
                'code'    => $code,
            ]);
            return;
        }

        // ── Settlement running — retry ───────────────────────────────
        if ($code === ResultCode::NETWORK_BUSY->value) {
            Log::info('Ignite cancel: settlement in progress, retrying', [
                'line_id' => $line->id,
            ]);
            $this->release(30);
            return;
        }

        // ── Order never landed on Ignite — permanent fail ────────────
        // Ref mismatch (line created with a different ref), or the line
        // was marked posted but createOrder never actually landed.
        if ($code === ResultCode::ORDER_NOT_FOUND->value) {
            $line->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "ORDER_NOT_FOUND: ref={$partnerRef}",
            ]);

            Log::error('Ignite cancel: ORDER_NOT_FOUND — partner_ref mismatch or createOrder never landed', [
                'line_id'         => $line->id,
                'order_id'        => $order->id,
                'ref'             => $partnerRef,
                'ignite_order_no' => $line->ignite_order_no,
            ]);
            return;
        }

        // ── Any other business refusal — permanent fail ──────────────
        $line->update([
            'ignite_sync_status' => 'failed',
            'ignite_sync_error'  => "[{$code}] " . ($response['msg'] ?? 'unknown'),
        ]);

        Log::error('Ignite cancel: non-retryable refusal', [
            'line_id' => $line->id,
            'order_id'=> $order->id,
            'ref'     => $partnerRef,
            'code'    => $code,
            'msg'     => $response['msg'] ?? null,
        ]);
    }

    /**
     * Same as PostOrderToIgniteJob::buildPartnerRef() — MUST stay in sync.
     */
    protected function buildPartnerRef(string $orderRef, int $lineId): string
    {
        return $orderRef . '-' . $lineId;
    }

    /**
     * Clear the member cache so subsequent reads see fresh balances/CV.
     */
    protected function forgetMemberCache($order, $line): void
    {
        $user = $order->user;

        if ($user && ! empty($user->distributor_id)) {
            $mainBaId = preg_replace('/-(002|003)$/', '-001', $user->distributor_id);
            Cache::forget("ignite_member_{$mainBaId}");
            Cache::forget("ignite_binary_tree_{$mainBaId}");
        }
    }
}