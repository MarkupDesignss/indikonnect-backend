<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin-only, READ-ONLY post-confirmation preflight.
 * It never reserves an AWB, books a parcel, changes order state or charges money.
 * Only put the route inside BOTH auth:sanctum and admin middleware.
 */
final class IndiaPostDispatchReadinessController extends Controller
{
    public function show(Request $request, string $orderId): JsonResponse
    {
        if (!ctype_digit($orderId) || (int) $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order ID.'], 422);
        }

        $id = (int) $orderId;
        $order = DB::table('orders')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $blockers = [];
        $warnings = [];
        $checks = [];

        $confirmed = $order->status === 'confirmed' && !empty($order->confirmed_at);
        $checks['order_confirmed'] = $confirmed;
        if (!$confirmed) {
            $blockers[] = 'Order must be confirmed before India Post booking preparation.';
        }

        // Compare only server-side amounts. This is a cautious secondary check,
        // NOT a replacement for the existing trusted payment confirmation flow.
        $paid = (float) ($order->amount_paid ?? 0);
        $due = (float) ($order->total_payable ?? 0);
        $checks['payment_amount_consistent'] = $due >= 0 && ($paid + 0.01 >= $due);
        if (!$checks['payment_amount_consistent']) {
            $blockers[] = 'Paid amount does not match order payable amount. Verify payment before dispatch.';
        }

        $lines = DB::table('order_lines')->where('order_id', $id)->get([
            'id', 'product_id', 'variant_id', 'quantity', 'delivery_status',
        ]);
        $checks['order_has_items'] = $lines->isNotEmpty();
        if ($lines->isEmpty()) {
            $blockers[] = 'No order items found.';
        }
        $unsafeLineStatus = ['cancelled', 'cancel_pending', 'returned', 'refunded', 'return_pending', 'return_approved', 'failed', 'undelivered'];
        $checks['items_eligible'] = $lines->every(static fn ($line) =>
            (int) $line->quantity > 0 && !in_array(strtolower((string) $line->delivery_status), $unsafeLineStatus, true)
        );
        if (!$checks['items_eligible']) {
            $blockers[] = 'Some items are cancelled, returning, returned or otherwise not dispatchable.';
        }

        // An order may still say "confirmed" even when returns/refunds have
        // already been recorded. Never book a new shipment in that state.
        $activeReturns = DB::table('returns')->where('order_id', $id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'approved', 'partially_approved', 'received', 'completed'])
            ->count();
        $refunds = DB::table('refunds')->where('order_id', $id)
            ->whereIn('status', ['initiated', 'completed'])->count();
        $creditNotes = DB::table('credit_notes')->where('order_id', $id)->count();
        $checks['no_returns_refunds_or_credit_notes'] =
            $activeReturns === 0 && $refunds === 0 && $creditNotes === 0;
        if (!$checks['no_returns_refunds_or_credit_notes']) {
            $blockers[] = 'Order has active/completed returns, refunds, or credit notes. Finance/admin must reconcile before creating any shipment.';
        }

        // IMPORTANT: DB column is `addresses.postcode` (not the frontend JSON
        // `delivery_address.postal_code`, which may be null due to serialization).
        $address = DB::table('addresses')
            ->where('id', $order->delivery_address_id)
            ->where('user_id', $order->user_id)
            ->whereNull('deleted_at')
            ->first();
        $postcode = trim((string) ($address->postcode ?? ''));
        $state = trim((string) ($address->state ?? ''));
        $checks['delivery_address_exists'] = (bool) $address;
        $checks['delivery_pin_format_valid'] = preg_match('/^[1-9][0-9]{5}$/D', $postcode) === 1;
        if (!$address) {
            $blockers[] = 'Order delivery address not found in the database.';
        } else {
            if (!$checks['delivery_pin_format_valid']) {
                $blockers[] = 'Delivery PIN is missing or invalid in addresses.postcode. Correct the real delivery address before booking.';
            }
            if ($state === '') {
                $blockers[] = 'Delivery state is missing.';
            }
            if ($this->isTelangana($state)) {
                $blockers[] = 'Delivery to Telangana is blocked by IndieKonnect policy.';
            }
            if (trim((string) ($address->address_line_1 ?? '')) === '' || trim((string) ($address->city ?? '')) === '') {
                $blockers[] = 'Delivery street address or city is missing.';
            }
            if (trim((string) ($address->recipient_name ?? '')) === '') {
                $blockers[] = 'Recipient name is missing.';
            }
            if (!$this->validMobile($address->contact_number ?? null)) {
                $blockers[] = 'Recipient mobile is missing or invalid.';
            }
        }

        // Explicit admin warehouse choice is necessary. Don't silently choose
        // the default warehouse if multiple fulfilment locations exist.
        $warehouseId = (int) $request->query('warehouse_id', 0);
        $warehouse = $warehouseId > 0
            ? DB::table('warehouses')->where('id', $warehouseId)
                ->where('is_active', 1)->whereNull('deleted_at')->first()
            : null;
        $suggestedWarehouse = DB::table('warehouses')
            ->where('is_active', 1)->where('is_default', 1)->whereNull('deleted_at')
            ->value('id');
        $checks['warehouse_selected'] = (bool) $warehouse;
        if (!$warehouse) {
            $blockers[] = $warehouseId > 0
                ? 'Selected warehouse is not active or does not exist.'
                : 'Admin must select the actual dispatch warehouse using warehouse_id.';
        }

        $sourcePin = trim((string) ($warehouse->pincode ?? ''));
        if ($warehouse && preg_match('/^[1-9][0-9]{5}$/D', $sourcePin) !== 1) {
            $blockers[] = 'Warehouse source PIN is missing or invalid.';
        }
        if ($warehouse && (!$this->validMobile($warehouse->contact_number ?? null)
            || trim((string) ($warehouse->contact_person ?? '')) === ''
            || trim((string) ($warehouse->address_line_1 ?? '')) === '')) {
            $blockers[] = 'Warehouse sender contact or address is incomplete.';
        }

        // Current source products schema does not contain parcel measurements.
        // Let authorized warehouse staff supply measured packed dimensions.
        $weight = $request->query('weight_grams');
        $length = $request->query('length_cm');
        $width = $request->query('width_cm');
        $height = $request->query('height_cm');
        $measurementsProvided = $weight !== null && $length !== null && $width !== null && $height !== null;
        $validPackage = $measurementsProvided
            && filter_var($weight, FILTER_VALIDATE_INT) !== false
            && (int) $weight >= 1 && (int) $weight <= 35000
            && $this->inRange($length, 14, 150)
            && $this->inRange($width, 9, 150)
            && $this->inRange($height, 1, 150);
        $checks['measured_package_valid'] = (bool) $validPackage;
        if (!$validPackage) {
            $blockers[] = 'Enter measured Business Parcel weight_grams (1–35000), length_cm (14–150), width_cm (9–150), height_cm (1–150).';
        }

        if ($warehouse && $lines->isNotEmpty()) {
            foreach ($lines as $line) {
                $stock = DB::table('warehouse_stocks')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $line->product_id)
                    ->where(function ($q) use ($line) {
                        if ($line->variant_id === null) $q->whereNull('variant_id');
                        else $q->where('variant_id', $line->variant_id);
                    })
                    ->sum('quantity');
                if ($stock < (int) $line->quantity) {
                    $warnings[] = 'Warehouse stock record does not cover order line ' . (int) $line->id
                        . '. Verify physical stock/reservations before dispatch.';
                }
            }
        }

        $nodeUrl = rtrim((string) config('services.indiapost_node.url', ''), '/');
        $internalKey = (string) config('services.indiapost_node.api_key', '');
        $nodeEnabled = (bool) config('services.indiapost_node.enabled', false);
        $nodeConfigured = $nodeEnabled && str_starts_with($nodeUrl, 'https://') && $internalKey !== '';
        $checks['node_adapter_configured'] = $nodeConfigured;
        $destinationOffice = null;
        $sourceOffice = null;
        $existingShipmentCount = null;

        if (!$nodeConfigured) {
            $blockers[] = 'India Post Node.js adapter is disabled or missing HTTPS URL/internal key.';
        } else {
            $client = Http::acceptJson()->withHeaders(['x-internal-api-key' => $internalKey])
                ->connectTimeout(4)->timeout(12);
            // Never report ready when the upstream Node or India Post lookup fails.
            if ($checks['delivery_pin_format_valid'] && !$this->isTelangana($state)) {
                try {
                    $resp = $client->get($nodeUrl . '/api/indiapost/pincode/' . rawurlencode($postcode));
                    $offices = $this->extractOffices($resp->json());
                    if (!$resp->successful() || !$offices) {
                        $blockers[] = 'India Post could not verify the destination PIN or returned no post offices.';
                    } else {
                        $matching = array_values(array_filter($offices, function ($office) use ($state) {
                            return is_array($office)
                                && $this->normalizeState((string) ($office['state_name'] ?? $office['state'] ?? '')) === $this->normalizeState($state)
                                && $this->eligibleOffice($office);
                        }));
                        if (!$matching) {
                            $blockers[] = 'India Post PIN lookup state/delivery-office does not match the saved delivery address.';
                        } else {
                            $destinationOffice = $this->publicOfficeInfo($matching[0]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('India Post destination preflight lookup unavailable', ['order_id' => $id, 'type' => get_class($e)]);
                    $blockers[] = 'India Post destination PIN verification is temporarily unavailable.';
                }
            }

            if ($warehouse && preg_match('/^[1-9][0-9]{5}$/D', $sourcePin) === 1) {
                try {
                    $resp = $client->get($nodeUrl . '/api/indiapost/pincode/' . rawurlencode($sourcePin));
                    $offices = $this->extractOffices($resp->json());
                    $validOffices = array_values(array_filter($offices, fn ($o) => is_array($o) && $this->eligibleOffice($o)));
                    if (!$resp->successful() || !$validOffices) {
                        $blockers[] = 'India Post could not verify a delivery-capable source office for warehouse PIN.';
                    } else {
                        $sourceOffice = $this->publicOfficeInfo($validOffices[0]);
                    }
                } catch (Throwable $e) {
                    Log::warning('India Post source preflight lookup unavailable', ['order_id' => $id, 'type' => get_class($e)]);
                    $blockers[] = 'India Post source PIN verification is temporarily unavailable.';
                }
            }

            try {
                $shipResp = $client->get($nodeUrl . '/api/indiapost/orders/' . $id . '/shipments');
                $shipData = $shipResp->json();
                if (!$shipResp->successful() || !is_array($shipData) || ($shipData['success'] ?? false) !== true) {
                    $blockers[] = 'Could not check whether an AWB is already assigned; do not retry booking blindly.';
                } else {
                    $existing = is_array($shipData['shipments'] ?? null) ? $shipData['shipments'] : [];
                    $existingShipmentCount = count($existing);
                    if ($existingShipmentCount > 0) {
                        $blockers[] = 'India Post already has ' . $existingShipmentCount
                            . ' shipment record(s) for this order. Reconcile them before any new booking.';
                    }
                }
            } catch (Throwable $e) {
                Log::warning('India Post shipment existence check unavailable', ['order_id' => $id, 'type' => get_class($e)]);
                $blockers[] = 'Could not check for existing shipments; do not attempt booking.';
            }
        }

        // No UAT booking is performed here. `ready_for_preview` is deliberately
        // not `booked` or `approved_for_production`.
        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $id,
                'order_reference' => $order->order_reference,
                'order_status' => $order->status,
                'booking_performed' => false,
                'ready_for_preview' => count($blockers) === 0,
                'checks' => $checks,
                'blockers' => array_values(array_unique($blockers)),
                'warnings' => array_values(array_unique($warnings)),
                'destination' => [
                    'state' => $state ?: null,
                    'pincode' => $postcode ?: null,
                    'verified_office' => $destinationOffice,
                ],
                'warehouse' => [
                    'selected_id' => $warehouse ? (int) $warehouse->id : null,
                    'suggested_default_id' => $suggestedWarehouse ? (int) $suggestedWarehouse : null,
                    'source_pincode' => $sourcePin ?: null,
                    'verified_office' => $sourceOffice,
                ],
                'package' => $validPackage ? [
                    'article_type' => 'BUSINESS_PARCEL',
                    'physical_weight' => (int) $weight,
                    'length' => (float) $length,
                    'breadth_diameter' => (float) $width,
                    'height' => (float) $height,
                    'shape_of_article' => 'NROL',
                ] : null,
                'existing_indiapost_shipments' => $existingShipmentCount,
                'next_action' => count($blockers) === 0
                    ? 'Operator may prepare an authorized booking-preview payload and review assigned AWB; this endpoint does not submit.'
                    : 'Resolve all blockers before generating or submitting India Post booking payload.',
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function inRange($value, float $min, float $max): bool
    {
        return is_numeric($value) && is_finite((float) $value)
            && (float) $value >= $min && (float) $value <= $max;
    }

    private function validMobile($value): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return preg_match('/^[6-9][0-9]{9}$/D', $digits) === 1;
    }

    private function normalizeState(string $value): string
    {
        $v = preg_replace('/[^a-z]/', '', strtolower($value)) ?: '';
        $aliases = [
            'up' => 'uttarpradesh', 'uttaranchal' => 'uttarakhand',
            'nctofdelhi' => 'delhi', 'nationalcapitalterritoryofdelhi' => 'delhi',
            'orissa' => 'odisha', 'pondicherry' => 'puducherry',
            'jammuandkashmirut' => 'jammuandkashmir',
            'telengana' => 'telangana', 'telingana' => 'telangana',
            'ts' => 'telangana', 'tg' => 'telangana',
        ];
        return $aliases[$v] ?? $v;
    }

    private function isTelangana(string $value): bool
    {
        return $this->normalizeState($value) === 'telangana';
    }

    private function extractOffices($payload): array
    {
        if (!is_array($payload) || ($payload['success'] ?? false) !== true) return [];
        $current = $payload['data'] ?? null;
        for ($level = 0; $level < 4 && is_array($current); $level++) {
            if (array_is_list($current)) return $current;
            $current = $current['data'] ?? null;
        }
        return [];
    }

    private function eligibleOffice(array $o): bool
    {
        if (strtoupper((string) ($o['office_type_code'] ?? '')) === 'BPO') return false;
        $flag = $o['delivery_office_flag'] ?? null;
        if ($flag === null) return false; // do not invent provider eligibility
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function publicOfficeInfo(array $o): array
    {
        return [
            'name' => (string) ($o['office_name'] ?? ''),
            'office_id' => isset($o['office_id']) ? (string) $o['office_id'] : null,
            'state' => (string) ($o['state_name'] ?? $o['state'] ?? ''),
            'city' => (string) ($o['city_name'] ?? $o['city'] ?? ''),
        ];
    }
}
