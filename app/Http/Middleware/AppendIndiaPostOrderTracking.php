<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Local, read-only, dedicated order-line tracking enrichment. */
final class AppendIndiaPostOrderTracking
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (!$response instanceof JsonResponse || $response->getStatusCode() !== 200) return $response;
        $body = $response->getData(true);
        if (!is_array($body) || ($body['success'] ?? false) !== true || !is_array($body['data'] ?? null)) return $response;

        $ids = [];
        // Fail closed: never forward a parent AWB or an unverified India Post line summary.
        foreach ($body['data'] as &$entry) {
            if (!is_array($entry)) continue;
            // allOrder() now returns {id, order_reference, items}; also tolerate the older wrapper.
            $wrapped = is_array($entry['order'] ?? null);
            $parent = $wrapped ? $entry['order'] : $entry;
            $id = (int) ($parent['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
            $entry['indiapost'] = null;
            if ($wrapped) $entry['order']['indiapost'] = null;
            if (!is_array($entry['items'] ?? null)) continue;
            foreach ($entry['items'] as &$item) {
                if (!is_array($item)) continue;
                $item['indiapost'] = null;
                $details = $item['shipping_details'] ?? null;
                $company = is_array($details) ? (string) ($details['courier_company'] ?? '') : '';
                // Hide unverified manual India Post details from this response, not the database.
                if (preg_match('/india\s*post|speed\s*post|department\s+of\s+posts/i', $company)) {
                    $item['courier_tracking_number'] = null;
                    $item['shipping_details']['courier_tracking_number'] = null;
                }
            }
            unset($item);
        }
        unset($entry);
        $response->setData($body);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (!$ids) return $response;

        try {
            // Dedicated allocations only. Never guess ownership of NULL-reference legacy parcels.
            // Exact reference is also compared in PHP below (SQL collation may be case insensitive).
            $rows = DB::table('indiapost_awb_allocations as a')
                ->join('orders as o', function ($j) {
                    $j->on('o.id', '=', 'a.order_id')->on('o.order_reference', '=', 'a.order_reference');
                })
                ->join('order_lines as li', function ($j) {
                    $j->on('li.id', '=', 'a.order_line_id')->on('li.order_id', '=', 'o.id');
                })
                ->join('indiapost_order_line_links as l', function ($j) {
                    $j->on('l.order_id', '=', 'a.order_id')->on('l.order_line_id', '=', 'a.order_line_id')
                      ->on('l.order_reference', '=', 'a.order_reference')->on('l.barcode_no', '=', 'a.barcode_no');
                })
                ->leftJoin('indiapost_shipments as n', 'n.barcode_no', '=', 'a.barcode_no')
                ->leftJoin('indiapost_order_shipments as s', 's.barcode_no', '=', 'a.barcode_no')
                ->whereIn('a.order_id', array_values(array_unique($ids)))
                ->whereNull('o.deleted_at')
                ->whereNotNull('a.order_line_id')->whereNotNull('a.order_reference')
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('indiapost_order_line_links as other')
                      ->whereColumn('other.barcode_no', 'a.barcode_no')
                      ->where(function ($conflict) {
                          $conflict->whereColumn('other.order_id', '<>', 'a.order_id')
                              ->orWhereColumn('other.order_line_id', '<>', 'a.order_line_id')
                              ->orWhereColumn('other.order_reference', '<>', 'a.order_reference');
                      });
                })
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('indiapost_order_line_links as other')
                      ->whereColumn('other.order_id', 'a.order_id')
                      ->whereColumn('other.order_line_id', 'a.order_line_id')
                      ->where(function ($conflict) {
                          $conflict->whereColumn('other.barcode_no', '<>', 'a.barcode_no')
                              ->orWhereColumn('other.order_reference', '<>', 'a.order_reference');
                      });
                })
                ->get(['a.order_id', 'a.order_line_id', 'a.order_reference', 'a.barcode_no',
                    'o.order_reference as current_reference', 'l.order_reference as linked_reference',
                    'n.id as node_id', 'n.order_id as node_order_id', 'n.order_reference as node_reference',
                    'n.booking_status as node_status', 'n.latest_event as node_event', 'n.latest_event_at as node_event_at',
                    'n.provider_batch_id as node_batch',
                    's.id as local_id', 's.order_id as local_order_id', 's.order_line_id as local_line_id',
                    's.booking_status as local_status', 's.provider_accepted', 's.provider_batch_id as local_batch',
                    's.latest_event as local_event', 's.latest_event_at as local_event_at', 's.updated_at']);
            $groups = [];
            foreach ($rows as $r) {
                $key = (int)$r->order_id . ':' . (int)$r->order_line_id;
                $groups[$key][] = $r;
            }
            foreach ($body['data'] as &$entry) {
                if (!is_array($entry) || !is_array($entry['items'] ?? null)) continue;
                $parent = is_array($entry['order'] ?? null) ? $entry['order'] : $entry;
                $oid = (int) ($parent['id'] ?? 0);
                $ref = (string) ($parent['order_reference'] ?? '');
                foreach ($entry['items'] as &$item) {
                    if (!is_array($item)) continue;
                    $lid = (int) ($item['line_id'] ?? 0);
                    if ($oid < 1 || $lid < 1 || $ref === '') continue;
                    // Nested items inherit their parent's identity. If redundant IDs are present, reject conflicts.
                    if (array_key_exists('order_id', $item) && (int) $item['order_id'] !== $oid) continue;
                    if (array_key_exists('order_reference', $item) && $item['order_reference'] !== $ref) continue;
                    $matches = $groups[$oid . ':' . $lid] ?? [];
                    if (count($matches) !== 1) continue;
                    $r = $matches[0];
                    if (!$this->verified($r, $ref, $oid, $lid)) continue;
                    $awb = (string) $r->barcode_no;
                    $status = $r->node_id ? (string) $r->node_status : ($r->local_id ? (string) $r->local_status : 'allocated');
                    // A local attempt is not evidence of remote acceptance.
                    if (!$r->node_id && $r->local_id) $status = 'needs_reconciliation';
                    if ($r->node_id && $r->local_id && in_array((string)$r->local_status, ['unknown', 'submitting', 'needs_reconciliation'], true)) $status = 'needs_reconciliation';
                    $event = $r->node_event ?? $r->local_event;
                    $eventAt = $r->node_event_at ?? $r->local_event_at;
                    $item['indiapost'] = [
                        'order_id' => $oid, 'order_reference' => $ref, 'order_line_id' => $lid,
                        'shipment_id' => $r->local_id ? (int)$r->local_id : null,
                        'courier' => 'India Post', 'awb' => $awb,
                        'booking_status' => $status, 'tracking_status' => $status,
                        'provider_accepted' => $r->node_id && $r->local_id && (bool)$r->provider_accepted && !in_array($status, ['allocated','unknown','submitting','rejected','needs_reconciliation'], true),
                        'provider_batch_id' => $r->node_batch ?? $r->local_batch,
                        'latest_event' => $event, 'latest_event_code' => null, 'latest_event_at' => $eventAt,
                        'tracking_source' => $r->node_id || $r->local_id ? 'stored_tracking' : 'allocation',
                        'local_record_updated_at' => $r->updated_at,
                    ];
                    // Compatibility for the existing tracking-number column. Do not overwrite another courier's number.
                    if (empty($item['courier_tracking_number'])) {
                        $item['courier_tracking_number'] = $awb;
                    }
                    // The authoritative managed shipment data remains item.indiapost.
                    // No shipping_details row, delivery status or booking is created by this middleware.
                }
                unset($item);
            }
            unset($entry);
            $response->setData($body);
        } catch (\Throwable $e) {
            Log::error('India Post line enrichment failed', ['exception_type' => get_class($e)]);
            // Retain the sanitized response built above.
        }
        return $response;
    }

    private function verified(object $r, string $ref, int $oid, int $lid): bool
    {
        return $r->order_reference === $ref && $r->current_reference === $ref && $r->linked_reference === $ref
            && (int)$r->order_id === $oid && (int)$r->order_line_id === $lid
            && preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', (string)$r->barcode_no) === 1
            && (!$r->node_id || ((int)$r->node_order_id === $oid && $r->node_reference === $ref))
            && (!$r->local_id || ((int)$r->local_order_id === $oid && (int)$r->local_line_id === $lid));
    }
}
