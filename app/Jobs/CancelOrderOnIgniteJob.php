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
use Illuminate\Support\Facades\Log;

class CancelOrderOnIgniteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 20;
    public $timeout = 60;

    public function __construct(public int $orderLineId) {}

    public function handle(IgniteClient $ignite): void
    {
        $line = OrderLine::with('order')->find($this->orderLineId);

        if (!$line) {
            Log::warning('Ignite cancel: line not found', ['line_id' => $this->orderLineId]);
            return;
        }

        if (empty($line->ignite_order_no)) {
            Log::info('Ignite cancel: no ignite_order_no on line, skipping', ['line_id' => $line->id]);
            return;
        }

        if ($line->ignite_sync_status === 'cancelled') {
            Log::info('Ignite cancel: line already cancelled', ['line_id' => $line->id]);
            return;
        }

        $order      = $line->order;
        $partnerRef = $order->order_reference . '-' . $line->id;

        try {
            $response = $ignite->cancelOrder($partnerRef);
        } catch (\Throwable $e) {
            Log::error('Ignite cancelOrder threw', [
                'line_id' => $line->id, 'ref' => $partnerRef, 'error' => $e->getMessage(),
            ]);
            $this->release(30);
            return;
        }

        $code = $response['code'] ?? null;

        if ($code === ResultCode::OK->value) {
            $line->update([
                'ignite_sync_status' => 'cancelled',
                'ignite_sync_error'  => null,
            ]);
            Log::info('Ignite line cancelled', ['line_id' => $line->id, 'ref' => $partnerRef]);
            return;
        }

        if ($code === ResultCode::ORDER_ALREADY_CANCELLED->value) {
            $line->update(['ignite_sync_status' => 'cancelled']);
            Log::info('Ignite line already cancelled', ['line_id' => $line->id]);
            return;
        }

        if ($code === 5013) {
            Log::info('Ignite cancel: settlement in progress, retrying in 30s', ['line_id' => $line->id]);
            $this->release(30);
            return;
        }

        throw new \Exception(
            "Ignite cancelOrder failed [{$code}]: " . ($response['msg'] ?? 'unknown')
        );
    }
}