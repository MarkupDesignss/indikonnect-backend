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
 * Customer-safe, order-scoped proxy for the protected Node.js India Post adapter.
 *
 * Supports both existing order ID URLs and new order reference URLs.
 * The Node adapter continues to receive the verified internal order ID.
 * Never accepts a public, arbitrary barcode for tracking.
 */
class IndiaPostOrderTrackingController extends Controller
{
    /**
     * EXISTING URL: GET /api/orders/{orderId}/indiapost/tracking
     * Kept unchanged for backward compatibility.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if (!ctype_digit($orderId) || (int) $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order.'], 422);
        }

        // Ownership is checked before contacting the internal Node adapter.
        $order = Order::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $orderId)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return $this->trackingForVerifiedOrder($order);
    }

    /**
     * NEW URL: GET /api/orders/by-reference/{orderReference}/indiapost/tracking
     * Finds an order by the real orders.order_reference field, with ownership.
     */
    public function showByReference(Request $request, string $orderReference): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $orderReference = trim($orderReference);
        if ($orderReference === '' || mb_strlen($orderReference) > 100) {
            return response()->json(['success' => false, 'message' => 'Invalid order reference.'], 422);
        }

        // Never resolve by reference without checking customer ownership.
        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('order_reference', $orderReference)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return $this->trackingForVerifiedOrder($order);
    }

    /**
     * Called only after an order has been resolved and ownership verified.
     */
    private function trackingForVerifiedOrder(Order $order): JsonResponse
    {
        $base = rtrim((string) config('services.indiapost_node.url', ''), '/');
        $key = (string) config('services.indiapost_node.api_key', '');
        if (!(bool) config('services.indiapost_node.enabled', false) || !$base || !$key) {
            return response()->json([
                'success' => false,
                'message' => 'Courier tracking is temporarily unavailable.',
            ], 503);
        }

        try {
            $client = Http::acceptJson()
                ->withHeaders(['x-internal-api-key' => $key])
                ->connectTimeout(4)
                ->timeout(18);

            // IMPORTANT: Node.js existing shipment & AWB APIs are keyed by
            // internal order ID. Do NOT replace this with order_reference.
            $response = $client->get($base . '/api/indiapost/orders/' . $order->id . '/shipments');
            $data = $response->json();
            if (!$response->successful() || !is_array($data) || ($data['success'] ?? false) !== true) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to retrieve shipment details.',
                ], 502);
            }

            // Only rows actually bound to this verified order are permitted.
            $rows = array_values(array_filter(
                is_array($data['shipments'] ?? null) ? $data['shipments'] : [],
                fn ($s) => is_array($s)
                    && (int) ($s['order_id'] ?? 0) === (int) $order->id
                    && preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', strtoupper((string) ($s['barcode_no'] ?? ''))) === 1
                    && !in_array(strtolower((string) ($s['booking_status'] ?? '')), ['rejected', 'unknown', 'submitting'], true)
            ));

            // Cap live tracking calls per customer request.
            $rows = array_slice($rows, 0, 20);
            $barcodes = array_values(array_unique(array_map(
                fn ($s) => strtoupper((string) $s['barcode_no']),
                $rows
            )));

            $providerByBarcode = [];
            $warning = null;
            $allocatedAwb = null;
            $allocatedAt = null;

            // An AWB can exist in allocations before a shipment exists.
            // This call only reads the allocation; it does NOT book an article.
            if (count($barcodes) === 0) {
                try {
                    $allocationResponse = $client->get($base . '/api/indiapost/orders/' . $order->id . '/awb');
                    $allocationData = $allocationResponse->json();

                    if ($allocationResponse->successful()
                        && is_array($allocationData)
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

            if (count($barcodes) > 0) {
                try {
                    $tracking = $client->post($base . '/api/indiapost/tracking/bulk', [
                        'bulk' => $barcodes,
                    ]);
                    $payload = $tracking->json();

                    if ($tracking->successful()
                        && is_array($payload)
                        && ($payload['success'] ?? false) === true
                        && is_array($payload['providerResponse']['data'] ?? null)
                    ) {
                        foreach ($payload['providerResponse']['data'] as $record) {
                            if (!is_array($record)) {
                                continue;
                            }
                            $number = strtoupper((string) ($record['booking_details']['article_number'] ?? ''));
                            if ($number !== '' && in_array($number, $barcodes, true)) {
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
                    if (!is_array($event)) {
                        continue;
                    }
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

                $latestProviderEvent = $events[0] ?? null;
                $shipments[] = [
                    'barcode' => $barcode,
                    // Booking status is NOT the same as courier delivery status.
                    'status' => $this->safeText($item['booking_status'] ?? '', 50) ?: 'pending',
                    'latest_event' => $latestProviderEvent['event']
                        ?? $this->safeText($item['latest_event'] ?? null, 160),
                    'latest_event_at' => $latestProviderEvent['date']
                        ?? $this->safeText($item['latest_event_at'] ?? null, 60),
                    'tracking_source' => $latestProviderEvent ? 'provider' : 'stored_tracking',
                    'events' => $events,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => (int) $order->id,
                    'order_reference' => (string) $order->order_reference,
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
            return response()->json([
                'success' => false,
                'message' => 'Courier tracking is temporarily unavailable.',
            ], 502);
        }
    }

    private function safeText($value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
