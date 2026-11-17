<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Sync carrier records by verified order and parcel; never infer product delivery. */
final class IndiaPostShipmentSyncService
{
    private const MAX_PARCELS = 20;
    private const STATUSES = [
        'submitting', 'submitted', 'rejected', 'unknown', 'booked',
        'picked_up', 'pickup_requested', 'pickup_cancelled', 'in_transit',
        'out_for_delivery', 'delivered', 'returned_to_sender', 'return_in_transit',
        'on_hold', 'event_received', 'needs_reconciliation',
    ];

    private function client(): array
    {
        $key = (string) config('services.indiapost_node.api_key', '');
        $url = rtrim((string) config('services.indiapost_node.url', ''), '/');
        if (!(bool) config('services.indiapost_node.enabled', false)
            || !str_starts_with($url, 'https://') || $key === '') {
            throw new RuntimeException('India Post Node service is not configured.');
        }
        return [Http::acceptJson()->withHeaders(['x-internal-api-key' => $key])
            ->connectTimeout(4)->timeout(30), $url];
    }

    /** Existing admin sync route. Preserve single-parcel response fields. */
    public function syncOne(int $orderId): array
    {
        $order = $this->order($orderId);
        $locals = DB::table('indiapost_order_shipments')->where('order_id', $orderId)
            ->orderBy('id')->limit(self::MAX_PARCELS + 1)->get()->all();
        if ($locals === []) {
            throw new RuntimeException('No local India Post booking record for order.');
        }
        [$http, $base] = $this->client();
        $path = $base . '/api/indiapost/orders/' . $orderId . '/shipments';
        $lookup = fn () => $this->lookup($http, $path, [], $order, null);
        return $this->syncParcels($http, $base, $order, $locals, $lookup, null);
    }

    /** Internal admin service method; requires a separately authorized Laravel route. */
    public function syncLine(int $orderId, int $orderLineId): array
    {
        $order = $this->order($orderId);
        if ($orderLineId < 1 || !DB::table('order_lines')->where('id', $orderLineId)
            ->where('order_id', $orderId)->exists()) {
            throw new RuntimeException('Order line not found for this order.');
        }
        [$http, $base] = $this->client();
        $path = $base . '/api/indiapost/orders/' . $orderId . '/lines/' . $orderLineId . '/shipments';
        $lookup = fn () => $this->lookup($http, $path,
            ['orderReference' => (string) $order->order_reference], $order, $orderLineId);
        $mapped = $lookup();
        if ($mapped['mappingStatus'] === 'unmapped') {
            return $this->result($order, $orderLineId, [], 'No parcel linked to this order line.', 'unmapped');
        }
        if ($mapped['mappingStatus'] !== 'linked') {
            throw new RuntimeException('Order-line mapping requires manual reconciliation.');
        }
        if ($mapped['shipments'] === []) {
            return $this->result($order, $orderLineId, [], 'No booked shipment linked to this line yet.', 'linked');
        }
        $remote = $this->verifiedRows($mapped['shipments'], $order);
        if (count($remote) > self::MAX_PARCELS) {
            throw new RuntimeException('Too many parcels; reconcile before syncing.');
        }
        $locals = DB::table('indiapost_order_shipments')->where('order_id', $orderId)
            ->whereIn('barcode_no', array_keys($remote))->orderBy('id')->get()->all();
        if (count($locals) !== count($remote)) {
            throw new RuntimeException('Local shipment record missing; manual reconciliation required.');
        }
        return $this->syncParcels($http, $base, $order, $locals, $lookup, $orderLineId);
    }

    private function order(int $orderId): object
    {
        $order = DB::table('orders')->where('id', $orderId)->whereNull('deleted_at')->first();
        if (!$order || trim((string) $order->order_reference) === '') {
            throw new RuntimeException('Order not found or missing reference.');
        }
        return $order;
    }

    private function lookup($http, string $path, array $query, object $order, ?int $lineId): array
    {
        $response = $http->get($path, $query);
        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['success'] ?? false) !== true
            || !is_array($data['shipments'] ?? null)) {
            throw new RuntimeException('Node shipment lookup failed; no local status changed.');
        }
        if ($lineId !== null && ((string) ($data['orderId'] ?? '') !== (string) $order->id
            || ($data['orderReference'] ?? null) !== (string) $order->order_reference
            || (string) ($data['orderLineId'] ?? '') !== (string) $lineId
            || !in_array($data['mappingStatus'] ?? null, ['linked', 'unmapped', 'reconciliation_required'], true)
            || !is_array($data['allocations'] ?? null)
            || ($data['mappingStatus'] === 'unmapped' && ($data['shipments'] !== [] || $data['allocations'] !== [])))) {
            throw new RuntimeException('Order-line response identity mismatch.');
        }
        return $data;
    }

    /** Refuse ambiguous, duplicate, or cross-order AWBs before refreshing. */
    private function verifiedRows(array $rows, object $order): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid Node shipment row.');
            $barcode = (string) ($row['barcode_no'] ?? '');
            if (!preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', $barcode)
                || (string) ($row['order_id'] ?? '') !== (string) $order->id
                || ($row['order_reference'] ?? null) !== (string) $order->order_reference
                || isset($result[$barcode])) {
                throw new RuntimeException('Shipment/AWB ownership mismatch; reconcile manually.');
            }
            $status = strtolower((string) ($row['booking_status'] ?? ''));
            if (!in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Unknown carrier shipment status.');
            }
            $result[$barcode] = $row;
        }
        return $result;
    }

    private function syncParcels($http, string $base, object $order, array $locals, callable $lookup, ?int $lineId): array
    {
        if ($locals === [] || count($locals) > self::MAX_PARCELS) {
            throw new RuntimeException('Invalid parcel count; reconcile before syncing.');
        }
        $barcodes = [];
        foreach ($locals as $local) {
            $barcode = (string) $local->barcode_no;
            if (!preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', $barcode) || isset($barcodes[$barcode])) {
                throw new RuntimeException('Invalid or duplicate locally linked AWB.');
            }
            $barcodes[$barcode] = true;
        }
        $before = $lookup();
        $this->assertMapped($before, $order, $barcodes, $lineId);

        // Read-only carrier tracking request (Node persists carrier events, never books here).
        $warning = null;
        try {
            $refresh = $http->post($base . '/api/indiapost/tracking/bulk', ['bulk' => array_keys($barcodes)]);
            $payload = $refresh->json();
            if (!$refresh->successful() || !is_array($payload) || ($payload['success'] ?? false) !== true
                || !is_array($payload['providerResponse']['data'] ?? null)) {
                $warning = 'Carrier refresh failed; last saved local status only.';
            }
        } catch (Throwable $e) {
            $warning = 'Carrier refresh failed; last saved local status only.';
        }
        if ($warning !== null) {
            Log::warning('India Post tracking refresh failed', ['order_id' => $order->id, 'order_line_id' => $lineId]);
            return $this->result($order, $lineId, array_map(fn ($row) => $this->parcelResult($row, false), $locals),
                $warning, $lineId === null ? null : 'linked');
        }

        $after = $lookup();
        $remote = $this->assertMapped($after, $order, $barcodes, $lineId);
        $results = DB::transaction(function () use ($order, $locals, $remote, $lineId): array {
            $currentOrder = DB::table('orders')->where('id', $order->id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$currentOrder || (string) $currentOrder->order_reference !== (string) $order->order_reference) {
                throw new RuntimeException('Order identity changed during sync.');
            }
            if ($lineId !== null && !DB::table('order_lines')->where('id', $lineId)
                ->where('order_id', $order->id)->exists()) {
                throw new RuntimeException('Order line changed during sync.');
            }
            $results = [];
            foreach ($locals as $local) {
                $current = DB::table('indiapost_order_shipments')->where('id', $local->id)
                    ->where('order_id', $order->id)->where('barcode_no', $local->barcode_no)->lockForUpdate()->first();
                if (!$current || (string) $current->barcode_no !== (string) $local->barcode_no) {
                    throw new RuntimeException('Local shipment changed during sync.');
                }
                $next = $remote[(string) $current->barcode_no];
                $status = strtolower((string) $next['booking_status']);
                // Concurrent booking or webhook updates must not be overwritten by an older snapshot.
                if ((string) $current->updated_at !== (string) $local->updated_at
                    || (string) $current->booking_status !== (string) $local->booking_status
                    || (string) $current->latest_event_at !== (string) $local->latest_event_at
                    || (string) $current->latest_event !== (string) $local->latest_event
                    || !$this->canApply($current, $next)) {
                    $results[] = $this->parcelResult($current, false);
                    continue;
                }
                if ((string) $current->booking_status === $status
                    && (string) ($current->latest_event ?? '') === (string) ($next['latest_event'] ?? '')
                    && (string) ($current->latest_event_at ?? '') === (string) ($next['latest_event_at'] ?? '')
                    && ($status !== 'delivered' || $current->delivered_at)) {
                    $results[] = $this->parcelResult($current, false, true);
                    continue;
                }
                $updates = [
                    'booking_status' => $status,
                    'latest_event' => $this->safeText($next['latest_event'] ?? null, 160),
                    'latest_event_at' => $this->safeText($next['latest_event_at'] ?? null, 60),
                    'delivered_at' => $status === 'delivered' ? ($current->delivered_at ?: now()) : $current->delivered_at,
                    'updated_at' => now(),
                ];
                DB::table('indiapost_order_shipments')->where('id', $current->id)
                    ->where('order_id', $order->id)->where('barcode_no', $current->barcode_no)->update($updates);
                $results[] = $this->parcelResult((object) array_merge((array) $current, $updates), true);
            }
            return $results;
        }, 3);
        $skipped = count(array_filter($results, fn ($row) => !$row['local_shipment_updated'] && !$row['already_current']));
        return $this->result($order, $lineId, $results,
            $skipped ? 'Some shipment records were unchanged; stale or ambiguous status requires review.' : null,
            $lineId === null ? null : 'linked');
    }

    private function assertMapped(array $data, object $order, array $barcodes, ?int $lineId): array
    {
        if ($lineId !== null && ($data['mappingStatus'] ?? null) !== 'linked') {
            throw new RuntimeException('Order-line mapping changed; reconcile manually.');
        }
        $remote = $this->verifiedRows($data['shipments'], $order);
        // Both sides must agree exactly; never fall back from a line to the parent order AWB.
        if (array_diff_key($barcodes, $remote) || array_diff_key($remote, $barcodes)) {
            throw new RuntimeException('Shipment/AWB mapping mismatch; reconcile manually.');
        }
        return $remote;
    }

    private function eventTime($value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D', trim($value))) return null;
        try {
            // Node stores timezone-less DoP scans as India local time; offsets are respected.
            return (new DateTimeImmutable($value, new DateTimeZone('Asia/Kolkata')))->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function canApply(object $local, array $next): bool
    {
        $old = strtolower((string) $local->booking_status);
        $new = strtolower((string) $next['booking_status']);
        $oldAt = $this->eventTime($local->latest_event_at ?? null);
        $newAt = $this->eventTime($next['latest_event_at'] ?? null);
        if (!in_array($new, self::STATUSES, true)) return false;
        if (in_array($old, ['delivered', 'returned_to_sender'], true) && $new !== $old) return false;
        if (!empty($local->latest_event_at) && ($oldAt === null || $newAt === null || $newAt < $oldAt)) return false;
        if (in_array($new, ['submitting', 'submitted', 'rejected', 'unknown', 'needs_reconciliation', 'event_received'], true)
            && !in_array($old, ['submitting', 'submitted', 'rejected', 'unknown', 'needs_reconciliation', 'event_received'], true)) return false;
        // Movement without a parseable scan never establishes delivery.
        if (in_array($new, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'returned_to_sender', 'return_in_transit'], true)
            && $newAt === null) return false;
        // Do not regress along the normal outbound movement path. Exception and return states remain separate.
        $rank = ['booked' => 0, 'picked_up' => 1, 'in_transit' => 2, 'out_for_delivery' => 3, 'delivered' => 4];
        if (isset($rank[$old], $rank[$new]) && $rank[$new] < $rank[$old]) return false;
        return true;
    }

    private function safeText($value, int $max): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function parcelResult(object $row, bool $updated, bool $alreadyCurrent = false): array
    {
        return ['awb' => (string) $row->barcode_no, 'courier_status' => (string) $row->booking_status,
            'latest_event' => $row->latest_event ?? null, 'latest_event_at' => $row->latest_event_at ?? null,
            'local_shipment_updated' => $updated, 'already_current' => $alreadyCurrent];
    }

    private function result(object $order, ?int $lineId, array $parcels, ?string $warning, ?string $mapping): array
    {
        $single = count($parcels) === 1 ? $parcels[0] : [];
        return [
            'order_id' => (int) $order->id, 'order_reference' => (string) $order->order_reference,
            'order_line_id' => $lineId, 'mapping_status' => $mapping,
            'awb' => $single['awb'] ?? null, 'courier_status' => $single['courier_status'] ?? null,
            'latest_event' => $single['latest_event'] ?? null, 'shipments' => $parcels,
            // Deliberately fail closed: an individual parcel is not proof of full order/line delivery.
            // Reintroduce business-state updates only through approved fulfillment/financial services.
            'legacy_order_updated' => false, 'auto_status_sync_enabled' => false,
            'auto_status_sync_config_requested' => (bool) config('indiapost_flow.auto_update_order_status', false),
            'order_line_status_updated' => false, 'warning' => $warning,
        ];
    }
}
