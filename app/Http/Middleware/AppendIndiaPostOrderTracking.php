<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Read-only, local, dedicated order-line AWB summary for the admin orders list. */
final class AppendIndiaPostOrderTracking
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (!$response instanceof JsonResponse || $response->getStatusCode() !== 200) return $response;
        $body = $response->getData(true);
        if (!is_array($body) || ($body['success'] ?? false) !== true || !is_array($body['data'] ?? null)) return $response;

        // Supports both the list and the optional single-order details route.
        $single = isset($body['data']['id'], $body['data']['items']);
        $entries = $single ? [$body['data']] : $body['data'];
        $ids = [];
        foreach ($entries as &$entry) {
            if (!is_array($entry)) continue;
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
                // Do not expose unverified manual India Post AWBs; leave other couriers untouched.
                if (preg_match('/india\s*post|speed\s*post|department\s+of\s+posts/i', $company)) {
                    $item['courier_tracking_number'] = null;
                    $item['shipping_details']['courier_tracking_number'] = null;
                }
            }
            unset($item);
        }
        unset($entry);
        $body['data'] = $single ? $entries[0] : $entries;
        $response->setData($body);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (!$ids) return $response;

        $stage = 'orders';
        try {
            // Separate bulk reads avoid comparing string columns with different DB collations.
            // A read transaction provides a consistent snapshot on InnoDB under REPEATABLE READ.
            $summary = DB::transaction(function () use ($ids, &$stage) {
                $result = [];
                foreach (array_chunk(array_values(array_unique($ids)), 500) as $batch) {
                    $stage = 'orders';
                    $orders = DB::table('orders')->whereIn('id', $batch)->whereNull('deleted_at')
                        ->get(['id', 'order_reference'])->keyBy('id');
                    $stage = 'order_lines';
                    $lines = DB::table('order_lines')->whereIn('order_id', $batch)->get(['id','order_id'])->keyBy('id');
                    $stage = 'allocations';
                    $allocations = DB::table('indiapost_awb_allocations')->whereIn('order_id', $batch)
                        ->whereNotNull('order_line_id')->get(['order_id','order_line_id','order_reference','barcode_no']);
                    $awbs = $allocations->pluck('barcode_no')->filter(fn ($v) => is_string($v) && preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', $v) === 1)->unique()->values()->all();
                    if (!$awbs) continue;
                    $stage = 'links';
                    // Read both by order scope and by barcode to detect conflicting links, even across orders.
                    $scopeLinks = DB::table('indiapost_order_line_links')->whereIn('order_id', $batch)
                        ->get(['order_id','order_line_id','order_reference','barcode_no']);
                    $scopeGroups = $scopeLinks->groupBy(fn ($r) => $r->order_id.':'.$r->order_line_id);
                    $linksByAwb = collect(); $nodes = collect(); $locals = collect();
                    foreach (array_chunk($awbs, 500) as $awbBatch) {
                        $stage = 'links_by_barcode';
                        $linksByAwb = $linksByAwb->concat(DB::table('indiapost_order_line_links')->whereIn('barcode_no', $awbBatch)
                            ->get(['order_id','order_line_id','order_reference','barcode_no']));
                        $stage = 'node_shipments';
                        $nodes = $nodes->concat(DB::table('indiapost_shipments')->whereIn('barcode_no', $awbBatch)
                            ->get(['id','order_id','order_reference','barcode_no','booking_status','latest_event','latest_event_at','provider_batch_id']));
                        $stage = 'local_shipments';
                        $locals = $locals->concat(DB::table('indiapost_order_shipments')->whereIn('barcode_no', $awbBatch)
                            ->get(['id','order_id','order_line_id','barcode_no','booking_status','provider_accepted','provider_batch_id','latest_event','latest_event_at','updated_at']));
                    }
                    $linksByAwb = $linksByAwb->groupBy('barcode_no');
                    $nodes = $nodes->groupBy('barcode_no');
                    $locals = $locals->groupBy('barcode_no');
                    $stage = 'verify_scope';
                    foreach ($allocations->groupBy(fn ($r) => $r->order_id.':'.$r->order_line_id) as $key => $group) {
                        if ($group->count() !== 1) continue;
                        $a = $group->first();
                        $oid = (int)$a->order_id; $lid = (int)$a->order_line_id;
                        $ref = $a->order_reference; $awb = $a->barcode_no;
                        if ($oid < 1 || $lid < 1 || !is_string($ref) || $ref === '' || !in_array($awb, $awbs, true)) continue;
                        if (($orders->get($oid)->order_reference ?? null) !== $ref || (int)($lines->get($lid)->order_id ?? 0) !== $oid) continue;
                        $scope = $scopeGroups->get($key, collect());
                        $byAwb = $linksByAwb->get($awb, collect());
                        if ($scope->count() !== 1 || $byAwb->count() !== 1) continue;
                        $link = $scope->first(); $reverse = $byAwb->first();
                        foreach ([$link, $reverse] as $l) {
                            if ((int)$l->order_id !== $oid || (int)$l->order_line_id !== $lid || $l->order_reference !== $ref || $l->barcode_no !== $awb) continue 2;
                        }
                        $ns = $nodes->get($awb, collect()); $ls = $locals->get($awb, collect());
                        if ($ns->count() > 1 || $ls->count() > 1) continue;
                        $n = $ns->first(); $s = $ls->first();
                        if ($n && ((int)$n->order_id !== $oid || $n->order_reference !== $ref || $n->barcode_no !== $awb)) continue;
                        if ($s && ((int)$s->order_id !== $oid || (int)$s->order_line_id !== $lid || $s->barcode_no !== $awb)) continue;
                        $status = $n ? (string)$n->booking_status : ($s ? 'needs_reconciliation' : 'allocated');
                        if ($s && in_array((string)$s->booking_status, ['unknown','submitting','needs_reconciliation'], true)) $status = 'needs_reconciliation';
                        $result[$key] = [
                            'order_id'=>$oid, 'order_reference'=>$ref, 'order_line_id'=>$lid,
                            'shipment_id'=>$s ? (int)$s->id : null, 'courier'=>'India Post', 'awb'=>$awb,
                            'booking_status'=>$status, 'tracking_status'=>$status,
                            'provider_accepted'=>$n && $s && (bool)$s->provider_accepted && !in_array($status,['allocated','unknown','submitting','rejected','needs_reconciliation'],true),
                            'provider_batch_id'=>$n->provider_batch_id ?? $s->provider_batch_id ?? null,
                            'latest_event'=>$n->latest_event ?? $s->latest_event ?? null,
                            'latest_event_at'=>$n->latest_event_at ?? $s->latest_event_at ?? null,
                            'latest_event_code'=>null, 'tracking_source'=>($n || $s) ? 'stored_tracking' : 'allocation',
                            'local_record_updated_at'=>$s->updated_at ?? null,
                        ];
                    }
                }
                return $result;
            });
            $stage = 'attach';
            foreach ($entries as &$entry) {
                if (!is_array($entry) || !is_array($entry['items'] ?? null)) continue;
                $parent = is_array($entry['order'] ?? null) ? $entry['order'] : $entry;
                $oid = (int)($parent['id'] ?? 0); $ref = $parent['order_reference'] ?? null;
                foreach ($entry['items'] as &$item) {
                    if (!is_array($item)) continue;
                    $lid = (int)($item['line_id'] ?? 0);
                    if (isset($item['order_id']) && (int)$item['order_id'] !== $oid) continue;
                    if (isset($item['order_reference']) && $item['order_reference'] !== $ref) continue;
                    $data = $summary[$oid.':'.$lid] ?? null;
                    if (!$data || $data['order_reference'] !== $ref) continue;
                    $item['indiapost'] = $data;
                    if (empty($item['courier_tracking_number'])) $item['courier_tracking_number'] = $data['awb'];
                }
                unset($item);
            }
            unset($entry);
            $body['data'] = $single ? $entries[0] : $entries;
            $response->setData($body);
        } catch (\Throwable $e) {
            // No query bindings, credentials or customer information in logs.
            Log::error('India Post line enrichment failed', ['stage'=>$stage, 'exception_type'=>get_class($e), 'code'=>(string)$e->getCode()]);
        }
        return $response;
    }
}
