<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Customer-safe order-scoped proxy for the protected Node.js India Post adapter.
 * CRITICAL: Only barcodes already bound to an order owned by the current user
 * are sent to the provider; there is no arbitrary public tracking-by-barcode route.
 */
class IndiaPostOrderTrackingController extends Controller
{
    public function show(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }
        if (!ctype_digit($orderId) || (int) $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order.'], 422);
        }

        // Verify ownership before making ANY internal Node.js call.
        $order = Order::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $orderId)
            ->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $base = rtrim((string) config('services.indiapost_node.url', ''), '/');
        $key = (string) config('services.indiapost_node.api_key', '');
        if (!(bool) config('services.indiapost_node.enabled', false) || !$base || !$key) {
            return response()->json(['success' => false, 'message' => 'Courier tracking is temporarily unavailable.'], 503);
        }

        try {
            $client = Http::acceptJson()
                ->withHeaders(['x-internal-api-key' => $key])
                ->connectTimeout(4)
                ->timeout(18);

            $response = $client->get($base . '/api/indiapost/orders/' . $order->id . '/shipments');
            $data = $response->json();
            if (!$response->successful() || !is_array($data) || ($data['success'] ?? false) !== true) {
                return response()->json(['success' => false, 'message' => 'Unable to retrieve shipment details.'], 502);
            }

            // Only shipments bound to this authenticated customer's order.
            $rows = array_values(array_filter(
                is_array($data['shipments'] ?? null) ? $data['shipments'] : [],
                fn ($s) => is_array($s)
                    && (int) ($s['order_id'] ?? 0) === (int) $order->id
                    && preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', strtoupper((string) ($s['barcode_no'] ?? '')))
                    && !in_array(strtolower((string) ($s['booking_status'] ?? '')), ['rejected', 'unknown', 'submitting'], true)
            ));

            // Bound how much provider data one customer request can fetch.
            $rows = array_slice($rows, 0, 20);
            $barcodes = array_values(array_unique(array_map(
                fn ($s) => strtoupper((string) $s['barcode_no']), $rows
            )));

            $providerByBarcode = [];
            $warning = null;
            $allocatedAwb = null;
            $allocatedAt = null;

            // A newly allocated barcode is in indiapost_awb_allocations, NOT yet
            // in indiapost_shipments. Query the protected Node adapter ONLY after
            // Laravel has verified this customer owns the requested order.
            // Read-only; never book or request tracking from India Post here.
            if (count($barcodes) === 0) {
                try {
                    $allocationResponse = $client->get($base . '/api/indiapost/orders/' . $order->id . '/awb');
                    $allocationData = $allocationResponse->json();
                    if ($allocationResponse->successful() && is_array($allocationData)
                        && ($allocationData['success'] ?? false) === true
                        && (int) ($allocationData['orderId'] ?? 0) === (int) $order->id
                    ) {
                        $allocation = $allocationData['allocation'] ?? null;
                        if (is_array($allocation)) {
                            $candidate = strtoupper((string) ($allocation['barcode_no'] ?? ''));
                            if (preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', $candidate) === 1) {
                                $allocatedAwb = $candidate;
                                $allocatedAt = $this->safeText($allocation['allocated_at'] ?? null, 60);
                            }
                        }
                    } else {
                        $warning = 'Allocated tracking number is temporarily unavailable.';
                    }
                } catch (Throwable $e) {
                    Log::warning('India Post AWB allocation lookup failed', [
                        'order_id' => $order->id,
                        'exception_type' => get_class($e),
                    ]);
                    $warning = 'Allocated tracking number is temporarily unavailable.';
                }
            }

            if (count($barcodes)) {
                try {
                    $tracking = Http::acceptJson()
                        ->withHeaders(['x-internal-api-key' => $key])
                        ->connectTimeout(4)
                        ->timeout(18)
                        ->post($base . '/api/indiapost/tracking/bulk', ['bulk' => $barcodes]);
                    $payload = $tracking->json();

                    if ($tracking->successful() && is_array($payload)
                        && ($payload['success'] ?? false) === true
                        && is_array($payload['providerResponse']['data'] ?? null)
                    ) {
                        foreach ($payload['providerResponse']['data'] as $record) {
                            if (!is_array($record)) continue;
                            $number = strtoupper((string) ($record['booking_details']['article_number'] ?? ''));
                            if ($number && in_array($number, $barcodes, true)) {
                                $providerByBarcode[$number] = $record;
                            }
                        }
                    } else {
                        $warning = 'Live courier movement updates are not available right now.';
                    }
                } catch (Throwable $e) {
                    Log::warning('India Post customer tracking refresh failed', [
                        'order_id' => $order->id,
                        'exception_type' => get_class($e),
                    ]);
                    $warning = 'Live courier movement updates are not available right now.';
                }
            }

            $shipments = [];
            foreach ($rows as $item) {
                $barcode = strtoupper((string) $item['barcode_no']);
                $record = $providerByBarcode[$barcode] ?? [];
                $events = [];
                foreach (is_array($record['tracking_details'] ?? null) ? $record['tracking_details'] : [] as $event) {
                    if (!is_array($event)) continue;
                    $events[] = [
                        'date' => $this->safeText($event['date'] ?? null, 60),
                        'event' => $this->safeText($event['event'] ?? '', 120) ?: 'Shipment update',
                        'office' => $this->safeText($event['office'] ?? null, 120),
                        'remarks' => $this->safeText($event['remarks'] ?? null, 200),
                        'rts' => (bool) ($event['rts'] ?? false),
                    ];
                }
                usort($events, function (array $a, array $b): int {
                    return (strtotime($b['date'] ?? '') ?: 0) <=> (strtotime($a['date'] ?? '') ?: 0);
                });
                $shipments[] = [
                    'barcode' => $barcode,
                    'status' => $this->safeText($item['booking_status'] ?? '', 50) ?: 'pending',
                    'latest_event' => $this->safeText($item['latest_event'] ?? null, 160),
                    'latest_event_at' => $this->safeText($item['latest_event_at'] ?? null, 60),
                    'events' => $events,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => (int) $order->id,
                    'allocated_awb' => $allocatedAwb,
                    'allocated_at' => $allocatedAt,
                    'shipments' => $shipments,
                    'warning' => $warning,
                ],
            ])->header('Cache-Control', 'private, no-store');
        } catch (Throwable $e) {
            Log::warning('India Post customer tracking lookup failed', [
                'order_id' => $order->id,
                'exception_type' => get_class($e),
            ]);
            return response()->json(['success' => false, 'message' => 'Courier tracking is temporarily unavailable.'], 502);
        }
    }

    private function safeText($value, int $max): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
