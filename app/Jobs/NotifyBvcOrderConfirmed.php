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

/**
 * Delivers the confirmed-order event to the EXISTING IndieKonnect Node backend.
 * NO BVC shipment booking is performed by this job.
 */
class NotifyBvcOrderConfirmed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 20;

    public function __construct(public int $orderId)
    {
        // Uses the DB queue; install jobs table and run a worker before enabling.
        $this->onConnection('database')->onQueue('bvc');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(): void
    {
        if (!config('services.bvc_node.enabled', false)) {
            return;
        }

        $order = Order::query()->select(['id', 'status', 'confirmed_at'])
            ->find($this->orderId);

        // Never notify Node for pending, failed, refunded, or cancelled orders.
        if (!$order || $order->status !== 'confirmed' || !$order->confirmed_at) {
            Log::notice('BVC order notification skipped: order not confirmed', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        $baseUrl = rtrim((string) config('services.bvc_node.url'), '/');
        $apiKey = (string) config('services.bvc_node.api_key');

        if (!str_starts_with($baseUrl, 'https://') || $apiKey === '') {
            throw new RuntimeException('BVC Node HTTPS URL or server-side API key is missing');
        }

        $endpoint = $baseUrl . '/api/indiekonnect/bvc/orders/'
            . (int) $order->id . '/confirmed';

        $response = Http::acceptJson()
            ->withHeaders(['x-internal-api-key' => $apiKey])
            ->connectTimeout(4)
            ->timeout(12)
            ->post($endpoint, ['event' => 'order.confirmed']);

        // A 409 means the receiving service no longer sees a confirmed order.
        // Do NOT retry this business-state conflict or ever trigger a booking.
        if ($response->status() === 409) {
            Log::warning('BVC order notification rejected: order no longer confirmed', [
                'order_id' => $order->id,
            ]);
            return;
        }

        if (!$response->successful()) {
            $error = new RuntimeException(
                'BVC Node event receiver HTTP ' . $response->status()
            );
            // Invalid authentication/routes require configuration fixes, not retries.
            if (in_array($response->status(), [400, 401, 403, 404, 422], true)) {
                $this->fail($error);
                return;
            }
            throw $error;  // 429/5xx: queue will retry with backoff
        }

        $result = $response->json();
        if (!is_array($result)
            || ($result['success'] ?? false) !== true
            || ($result['eventStored'] ?? false) !== true
            || ($result['liveBookingPerformed'] ?? true) !== false
        ) {
            throw new RuntimeException('BVC Node returned an unexpected event acknowledgement');
        }

        Log::info('BVC order confirmation delivered to Node.js', [
            'order_id' => $order->id,
            'duplicate' => (bool) ($result['alreadyReceived'] ?? false),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('BVC order event delivery failed; requires review', [
            'order_id' => $this->orderId,
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
