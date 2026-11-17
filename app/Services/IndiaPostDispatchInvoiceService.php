<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderShippingDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/** Only call for trusted carrier evidence. Never call on AWB allocation/booking. */
class IndiaPostDispatchInvoiceService
{
    public function enabled(): bool
    {
        return (bool) config('indiapost_webhook.dispatch_invoice_enabled', false)
            && (bool) config('indiapost_webhook.apply_shipment_status', false);
    }

    public function isDispatchStatus(?string $status): bool
    {
        // Change only after verifying the actual mapper/provider semantics.
        return $status !== null && in_array($status,
            (array) config('indiapost_webhook.dispatch_invoice_statuses', ['dispatched']), true);
    }

    private function skipped(string $reason, ?object $shipment = null): array
    {
        if ($shipment) {
            Log::warning('India Post dispatch invoice requires reconciliation', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'reason' => $reason,
            ]);
        }
        return ['generated' => 0, 'reason' => $reason];
    }

    public function apply(int $shipmentId, ?string $status, string $occurredAt): array
    {
        if (!$this->enabled() || !$this->isDispatchStatus($status)) {
            return ['generated' => 0, 'reason' => 'not_enabled_or_not_dispatch'];
        }

        return DB::transaction(function () use ($shipmentId, $occurredAt) {
            $pointer = DB::table('indiapost_order_shipments')->where('id', $shipmentId)->first();
            if (!$pointer) return $this->skipped('shipment_not_found');

            $order = Order::whereKey($pointer->order_id)->lockForUpdate()->first();
            if (!$order) return $this->skipped('order_not_found', $pointer);

            $shipment = DB::table('indiapost_order_shipments')->where('id', $shipmentId)
                ->where('order_id', $order->id)->lockForUpdate()->first();
            if (!$shipment) return $this->skipped('shipment_changed', $pointer);
            if (!in_array((string) $shipment->booking_status, ['submitted', 'booked'], true)) {
                return $this->skipped('booking_requires_reconciliation', $shipment);
            }
            if (empty($order->confirmed_at) || (float) $order->total_payable < 0
                || (float) $order->amount_paid + 0.01 < (float) $order->total_payable) {
                return $this->skipped('payment_or_confirmation_not_verified', $shipment);
            }
            // Missing mapping column is not equivalent to a whole-order shipment.
            if (!property_exists($shipment, 'order_line_id')) {
                return $this->skipped('order_line_mapping_column_missing', $shipment);
            }
            $lineId = $shipment->order_line_id;
            $awb = (string) $shipment->barcode_no;
            $other = DB::table('indiapost_order_shipments')->where('order_id', $order->id)
                ->where('id', '<>', $shipmentId);
            if ($lineId !== null) {
                $other->where(fn ($q) => $q->whereNull('order_line_id')->orWhere('order_line_id', $lineId));
            }
            if ($other->exists()) return $this->skipped('ambiguous_parcel_scope', $shipment);

            // Cross-check the local AWB allocation (including order reference).
            $allocation = DB::table('indiapost_awb_allocations')->where('order_id', $order->id)
                ->where('barcode_no', $awb)->where('order_reference', $order->order_reference);
            $lineId === null ? $allocation->whereNull('order_line_id') : $allocation->where('order_line_id', $lineId);
            if (!$allocation->exists()) return $this->skipped('awb_allocation_unverified', $shipment);

            $query = $order->lines()->orderBy('id')->lockForUpdate();
            if ($lineId !== null) $query->where('id', $lineId);
            $lines = $query->get();
            if ($lines->isEmpty()) return $this->skipped('no_matching_order_lines', $shipment);

            $details = [];
            foreach ($lines as $line) {
                if (!in_array((string) $line->delivery_status, ['confirmed', 'dispatched', 'shipped', 'delivered'], true)
                    || (int) $line->quantity <= 0 || (int) $line->returned_quantity !== 0
                    || !in_array($line->return_status, [null, 'none'], true)
                    || $line->cancelled_at || $line->cancellation_requested_at || (int) $line->is_replacement !== 0) {
                    return $this->skipped('ineligible_order_line_'.$line->id, $shipment);
                }
                $matching = OrderShippingDetail::where('order_id', $order->id)->where('order_line_id', $line->id)
                    ->lockForUpdate()->get();
                if ($matching->count() > 1) return $this->skipped('ambiguous_shipping_details', $shipment);
                $detail = $matching->first();
                if ($detail && !empty($detail->courier_tracking_number)
                    && strtoupper((string) $detail->courier_tracking_number) !== strtoupper($awb)) {
                    return $this->skipped('conflicting_tracking_number', $shipment);
                }
                if ($line->delivery_status !== 'confirmed' && !$detail
                    && !Invoice::where('order_line_id', $line->id)->exists()) {
                    return $this->skipped('previous_dispatch_not_verified', $shipment);
                }
                $details[$line->id] = $detail;
            }

            $changed = false;
            $dispatchedAt = Carbon::parse($occurredAt, 'UTC')->setTimezone(config('app.timezone', 'UTC'));
            foreach ($lines as $line) {
                if ($line->delivery_status === 'confirmed') {
                    $line->update(['delivery_status' => 'dispatched', 'dispatched_at' => $dispatchedAt]);
                    $changed = true;
                }
                $detail = $details[$line->id];
                if (!$detail) {
                    OrderShippingDetail::create([
                        'order_id' => $order->id, 'order_line_id' => $line->id,
                        'courier_tracking_number' => $awb, 'courier_company' => 'India Post',
                        'status' => $line->delivery_status,
                    ]);
                } elseif (!in_array((string) $detail->status, ['shipped', 'delivered'], true)) {
                    $detail->update([
                        'courier_tracking_number' => $awb, 'courier_company' => 'India Post',
                        'status' => $line->delivery_status,
                    ]);
                }
            }
            if ($changed) $order->updateOrderStatus();

            $missing = $lines->filter(fn ($line) => !Invoice::where('order_line_id', $line->id)
                ->lockForUpdate()->first())->values();
            if ($missing->isNotEmpty()) {
                app(InvoiceService::class)->generateInvoice($order, $missing);
            }
            return ['generated' => $missing->count(), 'order_line_ids' => $lines->pluck('id')->all()];
        }, 3);
    }

    /** Optional adapter: only works when the existing trusted sync persists the mapped status and UTC event timestamp locally. */
    public function reconcileOrder(int $orderId, ?int $lineId = null): array
    {
        if (!$this->enabled() || !config('indiapost_webhook.dispatch_invoice_sync_enabled', false)) return [];
        $table = 'indiapost_order_shipments';
        if (!Schema::hasColumns($table, ['courier_status', 'latest_event_at', 'order_line_id'])) return [];
        $query = DB::table($table)->where('order_id', $orderId)
            ->whereNotNull('latest_event_at')
            ->whereIn('courier_status', (array) config('indiapost_webhook.dispatch_invoice_statuses', ['dispatched']));
        if ($lineId !== null) $query->where('order_line_id', $lineId);
        $results = [];
        foreach ($query->get() as $shipment) {
            $results[] = $this->apply((int) $shipment->id, (string) $shipment->courier_status, (string) $shipment->latest_event_at);
        }
        return $results;
    }
}
