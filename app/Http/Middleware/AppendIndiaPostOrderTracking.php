<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only India Post enrichment for the existing admin/all-orders response.
 * Attaches a cached, order-scoped summary. Never books, allocates an AWB,
 * calls India Post on page load, or changes master order/delivery statuses.
 */
final class AppendIndiaPostOrderTracking
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (!$response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        $body = $response->getData(true);
        if (!is_array($body) || ($body['success'] ?? false) !== true || !is_array($body['data'] ?? null)) {
            return $response;
        }

        $ids = [];
        foreach ($body['data'] as $entry) {
            $id = $entry['order']['id'] ?? null;
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $response;
        }

        try {
            // The currently installed managed shipment schema is one parcel per order.
            // Only query local, already-synced data, not one carrier request per row.
            $shipments = DB::table('indiapost_order_shipments')
                ->whereIn('order_id', $ids)
                ->get([
                    'id', 'order_id', 'barcode_no', 'booking_status',
                    'provider_accepted', 'provider_batch_id',
                    'latest_event', 'latest_event_at', 'updated_at',
                ])
                ->keyBy('order_id');

            // The webhook is currently optional. It can record newer events
            // without auto-applying them to the master shipment status.
            $events = collect();
            if ($shipments->isNotEmpty()) {
                $orderIdsWithShipment = $shipments->keys()->all();
                $latestTimeByOrder = DB::table('indiapost_webhook_events')
                    ->select('order_id')
                    ->selectRaw('MAX(occurred_at) AS most_recent_at')
                    ->whereIn('order_id', $orderIdsWithShipment)
                    ->groupBy('order_id');

                $events = DB::table('indiapost_webhook_events as e')
                    ->joinSub($latestTimeByOrder, 'latest', function ($join): void {
                        $join->on('e.order_id', '=', 'latest.order_id')
                             ->on('e.occurred_at', '=', 'latest.most_recent_at');
                    })
                    ->whereIn('e.order_id', $orderIdsWithShipment)
                    ->orderByDesc('e.id')
                    ->get([
                        'e.order_id', 'e.shipment_id', 'e.article_number',
                        'e.event_code', 'e.event_description', 'e.occurred_at',
                        'e.mapped_status',
                    ])
                    ->unique('order_id')
                    ->keyBy('order_id');
            }

            foreach ($body['data'] as &$entry) {
                if (!isset($entry['order']) || !is_array($entry['order'])) {
                    continue;
                }
                $id = (int) ($entry['order']['id'] ?? 0);
                $shipment = $shipments->get($id);
                if (!$shipment) {
                    // No India Post allocation for this particular order.
                    $entry['order']['indiapost'] = null;
                    continue;
                }

                $awb = strtoupper((string) $shipment->barcode_no);
                $bookingStatus = (string) $shipment->booking_status;
                $latestEvent = $shipment->latest_event;
                $latestEventAt = $shipment->latest_event_at;
                $eventCode = null;
                $source = 'stored_tracking';
                $currentStatus = $bookingStatus;
                $webhook = $events->get($id);

                // Only accept webhook events matching the exact linked shipment/AWB.
                if ($webhook && (int) $webhook->shipment_id === (int) $shipment->id
                    && strtoupper((string) $webhook->article_number) === $awb) {
                    $webhookUnix = strtotime((string) $webhook->occurred_at . ' UTC') ?: 0;
                    $storedUnix = $latestEventAt ? (strtotime((string) $latestEventAt) ?: 0) : 0;
                    if ($webhookUnix >= $storedUnix) {
                        $latestEvent = $webhook->event_description ?: $webhook->event_code;
                        $latestEventAt = $webhook->occurred_at ? $webhook->occurred_at . ' UTC' : null;
                        $eventCode = $webhook->event_code;
                        $source = 'webhook';
                        // Do not misrepresent an unmapped event (e.g. BAG_CLOSE)
                        // as proof of booked, in transit, or delivered.
                        $mapped = (string) ($webhook->mapped_status ?? '');
                        if ($mapped !== '' && $this->rank($mapped) >= $this->rank($bookingStatus)) {
                            $currentStatus = $mapped;
                        }
                    }
                }

                $entry['order']['indiapost'] = [
                    'shipment_id' => (int) $shipment->id,
                    'courier' => 'India Post',
                    'awb' => $awb,
                    'booking_status' => $bookingStatus,
                    'tracking_status' => $currentStatus,
                    'provider_accepted' => (bool) $shipment->provider_accepted,
                    'provider_batch_id' => $shipment->provider_batch_id,
                    'latest_event' => $latestEvent,
                    'latest_event_code' => $eventCode,
                    'latest_event_at' => $latestEventAt,
                    'tracking_source' => $source,
                    'local_record_updated_at' => $shipment->updated_at,
                ];
            }
            unset($entry);

            $response->setData($body);
            // Admin pages should not display an earlier order-to-AWB mapping from a cache.
            $response->headers->set('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            // Preserve working all-orders API if optional enrichment is unavailable.
            Log::error('India Post all-orders enrichment failed', [
                'exception_type' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    private function rank(string $status): int
    {
        return match (strtolower($status)) {
            'submitting', 'submitted', 'unknown', 'rejected' => 0,
            'booked' => 1,
            'picked_up', 'dispatched' => 2,
            'in_transit', 'shipped' => 3,
            'out_for_delivery' => 4,
            'delivered' => 5,
            default => 0,
        };
    }
}
