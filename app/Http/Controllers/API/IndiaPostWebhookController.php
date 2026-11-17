<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\IndiaPostWebhookEventMapper;
use App\Services\IndiaPostDispatchInvoiceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class IndiaPostWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        // Fail closed until DoP authentication and event format are approved.
        if (!config('indiapost_webhook.enabled')) {
            return response()->json(['success' => false, 'message' => 'Webhook inactive'], 503);
        }
        if (!$this->authenticated($request)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['success' => false, 'message' => 'Payload too large'], 413);
        }
        $body = $request->json()->all();
        if (!is_array($body)) {
            return response()->json(['success' => false, 'message' => 'Invalid JSON'], 422);
        }
        $validator = Validator::make($body, [
            'article_number' => ['required', 'string', 'regex:/^[A-Za-z]{2}[0-9]{9}IN$/i'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'event_time' => ['required', 'date_format:H:i:s'],
            'event_code' => ['nullable', 'string', 'max:100', 'required_without:event_description'],
            'event_description' => ['nullable', 'string', 'max:250', 'required_without:event_code'],
            'event_office_facility_id' => ['nullable'],
            'event_office_name' => ['nullable', 'string', 'max:250'],
            'booking_ref_id' => ['nullable'],
            'bulk_customer_id' => ['nullable'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid event payload', 'errors' => $validator->errors()], 422);
        }
        $expectedCustomer = trim((string) config('indiapost_webhook.expected_customer_id'));
        if ($expectedCustomer !== '' && (string) ($body['bulk_customer_id'] ?? '') !== $expectedCustomer) {
            return response()->json(['success' => false, 'message' => 'Customer ID mismatch'], 422);
        }
        $barcode = strtoupper(trim($body['article_number']));
        $code = strtoupper(trim((string) ($body['event_code'] ?? '')));
        $description = trim((string) ($body['event_description'] ?? ''));
        $officeId = trim((string) ($body['event_office_facility_id'] ?? ''));
        try {
            $eventAt = Carbon::createFromFormat('!Y-m-d H:i:s', $body['event_date'].' '.$body['event_time'], 'Asia/Kolkata');
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Invalid event date/time'], 422);
        }
        if (!$eventAt) {
            return response()->json(['success' => false, 'message' => 'Invalid event date/time'], 422);
        }
        $occurredAt = $eventAt->utc()->format('Y-m-d H:i:s');
        $mappedStatus = IndiaPostWebhookEventMapper::map($code, $description);
        $hash = hash('sha256', implode('|', [
            $barcode, $body['event_date'], $body['event_time'], $officeId, $code, strtolower($description),
        ]));

        try {
            $result = DB::transaction(function () use ($body, $barcode, $code, $description, $officeId, $occurredAt, $mappedStatus, $hash) {
                // Match only allocated parcels; lock the order before shipment/lines.
                $pointer = DB::table('indiapost_order_shipments')->where('barcode_no', $barcode)->first();
                if ($pointer) {
                    Order::whereKey($pointer->order_id)->lockForUpdate()->first();
                }
                $shipment = $pointer ? DB::table('indiapost_order_shipments')
                    ->where('id', $pointer->id)->where('order_id', $pointer->order_id)
                    ->where('barcode_no', $barcode)->lockForUpdate()->first() : null;
                $invoiceService = app(IndiaPostDispatchInvoiceService::class);
                $invoiceResult = ['generated' => 0];
                $prior = DB::table('indiapost_webhook_events')->where('event_hash', $hash)->first();
                if ($prior) {
                    // Safe replay of previously matched dispatch events. No new invoice if one exists.
                    if ($shipment && (int) $prior->shipment_id === (int) $shipment->id
                        && $prior->mapped_status === $mappedStatus) {
                        $invoiceResult = $invoiceService->apply((int) $shipment->id, $mappedStatus, $occurredAt);
                    }
                    return ['duplicate' => true, 'matched' => (bool) $prior->shipment_id,
                        'shipment_updated' => false, 'status' => $prior->mapped_status, 'invoice' => $invoiceResult];
                }
                // Keep only operational fields; never persist full webhook payload.
                $inserted = DB::table('indiapost_webhook_events')->insertOrIgnore([
                    'event_hash' => $hash,
                    'article_number' => $barcode,
                    'order_id' => $shipment->order_id ?? null,
                    'shipment_id' => $shipment->id ?? null,
                    'event_code' => $code ?: null,
                    'event_description' => $description ?: null,
                    'event_office_facility_id' => $officeId ?: null,
                    'event_office_name' => substr((string) ($body['event_office_name'] ?? ''), 0, 250) ?: null,
                    'occurred_at' => $occurredAt,
                    'mapped_status' => $mappedStatus,
                    'booking_ref_id' => isset($body['booking_ref_id']) ? (string) $body['booking_ref_id'] : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted === 0) {
                    return ['duplicate' => true, 'matched' => $shipment !== null,
                        'shipment_updated' => false, 'status' => $mappedStatus, 'invoice' => $invoiceResult];
                }
                $updated = false;
                if ($shipment && $mappedStatus && config('indiapost_webhook.apply_shipment_status')) {
                    $updated = $this->updateShipmentIfSupported($shipment, $mappedStatus, $description, $occurredAt);
                    // A verified dispatch event can arrive late; do not regress courier status.
                    // The service still checks the linked parcel, order and line eligibility.
                    $invoiceResult = $invoiceService->apply((int) $shipment->id, $mappedStatus, $occurredAt);
                }
                return ['duplicate' => false, 'matched' => $shipment !== null,
                    'shipment_updated' => $updated, 'status' => $mappedStatus, 'invoice' => $invoiceResult];
            }, 3);

            if (!$result['matched']) {
                Log::warning('India Post webhook received for unmatched AWB', ['awb' => $barcode]);
            }
            return response()->json(['success' => true, 'accepted' => true] + $result, 202);
        } catch (Throwable $e) {
            // Invoice exceptions roll back the event so a provider retry can safely retry processing.
            Log::error('India Post webhook processing failed', ['type' => get_class($e), 'awb' => $barcode]);
            return response()->json(['success' => false, 'message' => 'Temporary processing failure'], 500);
        }
    }

    private function authenticated(Request $request): bool
    {
        $mode = (string) config('indiapost_webhook.auth_mode', 'disabled');
        if (!in_array($mode, ['token', 'ip', 'both'], true)) return false;
        if (in_array($mode, ['token', 'both'], true)) {
            $expected = (string) config('indiapost_webhook.token');
            $given = (string) $request->header('X-IndiaPost-Webhook-Token', '');
            if ($expected === '' || $given === '' || !hash_equals($expected, $given)) return false;
        }
        if (in_array($mode, ['ip', 'both'], true)) {
            $allowed = (array) config('indiapost_webhook.allowed_ips', []);
            if ($allowed === [] || !in_array((string) $request->ip(), $allowed, true)) return false;
        }
        return true;
    }

    private function updateShipmentIfSupported(object $shipment, string $newStatus, string $description, string $occurredAt): bool
    {
        $table = 'indiapost_order_shipments';
        $previousStatus = property_exists($shipment, 'courier_status') ? (string) $shipment->courier_status : null;
        if ($previousStatus && IndiaPostWebhookEventMapper::rank($newStatus) < IndiaPostWebhookEventMapper::rank($previousStatus)) return false;
        if (property_exists($shipment, 'latest_event_at') && $shipment->latest_event_at &&
            strtotime($occurredAt) < strtotime((string) $shipment->latest_event_at)) return false;
        $updates = [];
        if (Schema::hasColumn($table, 'latest_event')) $updates['latest_event'] = $description;
        if (Schema::hasColumn($table, 'latest_event_at')) $updates['latest_event_at'] = $occurredAt;
        if (Schema::hasColumn($table, 'courier_status')) $updates['courier_status'] = $newStatus;
        if ($newStatus === 'booked' && Schema::hasColumn($table, 'booking_status') &&
            (string) ($shipment->booking_status ?? '') === 'submitted') $updates['booking_status'] = 'booked';
        if ($updates === []) return false;
        if (Schema::hasColumn($table, 'updated_at')) $updates['updated_at'] = now();
        DB::table($table)->where('id', $shipment->id)->update($updates);
        return true;
    }
}
