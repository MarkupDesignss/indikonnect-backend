<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Only notifies India Post adapter of a paid, confirmed Laravel order. No booking. */
class NotifyIndiaPostOrderConfirmed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 20;

    public function __construct(public int $orderId)
    {
        $this->onConnection('database')->onQueue('indiapost');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(): void
    {
        if (!config('services.indiapost_node.enabled', false)) {
            return;
        }

        $order = Order::query()->select(['id', 'status', 'confirmed_at'])->find($this->orderId);
        if (!$order || $order->status !== 'confirmed' || !$order->confirmed_at) {
            Log::notice('India Post event skipped - order not confirmed', ['order_id' => $this->orderId]);
            return;
        }

        $url = rtrim((string) config('services.indiapost_node.url'), '/');
        $key = (string) config('services.indiapost_node.api_key');
        if (!str_starts_with($url, 'https://') || !$key) {
            throw new RuntimeException('India Post adapter URL/API key missing');
        }

        $response = Http::acceptJson()
            ->withHeaders(['x-internal-api-key' => $key])
            ->connectTimeout(4)
            ->timeout(12)
            ->post($url . '/api/indiapost/orders/' . $order->id . '/confirmed', [
                'event' => 'order.confirmed',
            ]);

        if ($response->status() === 409) {
            Log::warning('India Post adapter rejected non-confirmed order', ['order_id' => $order->id]);
            return;
        }
        if (!$response->successful()) {
            $error = new RuntimeException('India Post Node HTTP ' . $response->status());
            if (in_array($response->status(), [400, 401, 403, 404, 422], true)) {
                $this->fail($error);
                return;
            } 
            throw $error;
        }

        $data = $response->json();
        if (($data['success'] ?? false) !== true || ($data['eventStored'] ?? false) !== true || ($data['bookingPerformed'] ?? true) !== false) {
            throw new RuntimeException('India Post adapter returned unexpected response');
        }
        Log::info('India Post order event delivered', ['order_id'=>$order->id]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('India Post order event failed', [
            'order_id' => $this->orderId,
            'exception_type' => $exception ? get_class($exception) : null,
        ]);
    }
}
