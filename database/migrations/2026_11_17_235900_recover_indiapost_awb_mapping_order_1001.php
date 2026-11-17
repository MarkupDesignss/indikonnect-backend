<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One-time data repair. Pause courier booking/allocation and sync workers first.
    private const DATABASE = 'markcawm_indikonnect';
    private const ORDER_ID = 1001;
    private const REFERENCE = 'ORD6AFC41D250A74';
    // Verified against the supplied Node .env/config output. Do not use for production.
    private const SERIES_KEY = 'sandbox:ET:21433001-21434000';
    private const SERIES_START = 21433001;
    private const SERIES_END = 21434000;
    private const SERIES_PREFIX = 'ET';
    private const PARCELS = [
        1001 => ['id' => 4, 'barcode' => 'ET214330118IN', 'batch' => 'batch_3000064781_1794919688458_81e55bbc'],
        1002 => ['id' => 5, 'barcode' => 'ET214330121IN', 'batch' => 'batch_3000064781_1794920559870_0e7256b2'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDatabaseName() !== self::DATABASE) {
            throw new RuntimeException('Unexpected database. Recovery stopped.');
        }
        $required = [
            'orders' => ['id', 'order_reference', 'created_at', 'deleted_at'],
            'order_lines' => ['id', 'order_id'],
            'indiapost_order_shipments' => ['id', 'order_id', 'order_line_id', 'barcode_no', 'booking_status', 'provider_accepted', 'provider_batch_id', 'created_at', 'updated_at'],
            'indiapost_shipments' => ['order_id', 'order_reference', 'barcode_no', 'article_type', 'booking_status', 'provider_batch_id', 'latest_event', 'latest_event_at', 'created_at', 'updated_at'],
            'indiapost_awb_allocations' => ['order_id', 'order_line_id', 'order_reference', 'barcode_no', 'serial_no', 'series_key', 'created_at'],
            'indiapost_order_line_links' => ['order_id', 'order_reference', 'order_line_id', 'barcode_no', 'created_at'],
            'indiapost_awb_counters' => ['series_key', 'next_serial'],
        ];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Unexpected schema: {$table}. Recovery stopped.");
            }
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (strtoupper((string) ($engine->engine ?? '')) !== 'INNODB') {
                throw new RuntimeException("Non-transactional table: {$table}. Recovery stopped.");
            }
        }

        DB::transaction(function (): void {
            $order = DB::table('orders')->where('id', self::ORDER_ID)->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$order || $order->order_reference !== self::REFERENCE || !$order->created_at) {
                throw new RuntimeException('Order identity mismatch.');
            }
            $lineIds = array_keys(self::PARCELS);
            $barcodes = array_column(self::PARCELS, 'barcode');
            $lines = DB::table('order_lines')->where('order_id', self::ORDER_ID)->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
            if ($lines->count() !== count($lineIds)) {
                throw new RuntimeException('Expected order lines not found.');
            }
            $localRows = $this->relatedRows('indiapost_order_shipments', $barcodes);
            if ($localRows->count() !== count(self::PARCELS)) {
                throw new RuntimeException('Unexpected local parcel count or conflicting AWB ownership.');
            }

            // Node uses environment:prefix:start-end. The counter was confirmed empty;
            // initialize only this explicitly verified sandbox series, never guess one.
            $seriesKey = self::SERIES_KEY;
            $counters = DB::table('indiapost_awb_counters')->lockForUpdate()->get();
            foreach ($counters as $row) {
                if ((string) $row->series_key === $seriesKey) continue;
                if (!preg_match('/^([a-zA-Z0-9_-]+):([A-Z]{2}):([0-9]{8})-([0-9]{8})$/D', (string) $row->series_key, $m)) {
                    throw new RuntimeException('Unknown counter series; reconcile before recovery.');
                }
                if ($m[2] === self::SERIES_PREFIX && (int) $m[3] <= self::SERIES_END && (int) $m[4] >= self::SERIES_START) {
                    throw new RuntimeException('Overlapping AWB counter series; reconcile before recovery.');
                }
            }
            $matches = $counters->where('series_key', $seriesKey)->values();
            if ($matches->count() > 1) throw new RuntimeException('Duplicate counter series.');
            $counter = $matches->first();
            if ($counter && (!preg_match('/^[0-9]+$/D', (string) $counter->next_serial)
                || (int) $counter->next_serial < self::SERIES_START)) {
                throw new RuntimeException('Invalid existing AWB counter.');
            }

            // Include every locally recorded allocation, shipment and link in this range,
            // even records for other orders or with a non-successful booking status.
            // Workers/webhook writers must be paused. This cannot prove the external
            // provider has no other AWBs that are absent from the local database.
            $maxSerial = $this->highestRecordedSerial();

            $allocations = $this->relatedRows('indiapost_awb_allocations', $barcodes);
            $shipments = $this->relatedRows('indiapost_shipments', $barcodes);
            $links = $this->relatedRows('indiapost_order_line_links', $barcodes);
            foreach ([$allocations, $shipments, $links] as $rows) {
                foreach ($rows as $row) {
                    $lineId = array_search((string) $row->barcode_no, array_column(self::PARCELS, 'barcode', 'id'), true);
                    if ($lineId === false || (int) $row->order_id !== self::ORDER_ID || $row->order_reference !== self::REFERENCE) {
                        throw new RuntimeException('Existing target record has conflicting identity.');
                    }
                    if (property_exists($row, 'order_line_id')) {
                        $expectedLine = null;
                        foreach (self::PARCELS as $lid => $parcel) if ($parcel['barcode'] === $row->barcode_no) $expectedLine = $lid;
                        if ((int) $row->order_line_id !== $expectedLine) throw new RuntimeException('Existing target line conflicts.');
                    }
                }
            }

            foreach (self::PARCELS as $lineId => $parcel) {
                $barcode = $parcel['barcode'];
                $local = $localRows->firstWhere('barcode_no', $barcode);
                if (!$local || (int) $local->id !== $parcel['id'] || (int) $local->order_id !== self::ORDER_ID
                    || (int) $local->order_line_id !== $lineId || $local->booking_status !== 'submitted'
                    || (int) $local->provider_accepted !== 1 || (string) $local->provider_batch_id !== $parcel['batch']
                    || !$local->created_at || strtotime((string) $local->created_at) === false
                    || strtotime((string) $order->created_at) === false
                    || strtotime((string) $local->created_at) < strtotime((string) $order->created_at)) {
                    throw new RuntimeException("Local submission evidence is incomplete or changed for {$barcode}.");
                }
                $serial = substr($barcode, 2, 8);
                $this->validateBarcode($barcode);
                if (substr($barcode, 0, 2) !== self::SERIES_PREFIX || (int) $serial < self::SERIES_START || (int) $serial > self::SERIES_END) {
                    throw new RuntimeException("AWB outside verified sandbox series: {$barcode}.");
                }
                $maxSerial = max($maxSerial, (int) $serial);
                $identity = ['order_id' => self::ORDER_ID, 'order_reference' => self::REFERENCE, 'barcode_no' => $barcode];
                $a = $allocations->where('barcode_no', $barcode)->values();
                $s = $shipments->where('barcode_no', $barcode)->values();
                $l = $links->where('barcode_no', $barcode)->values();
                if ($a->count() > 1 || $s->count() > 1 || $l->count() > 1) throw new RuntimeException("Duplicate target records for {$barcode}.");
                foreach ([$a->first(), $s->first(), $l->first()] as $existing) {
                    if ($existing && (!$existing->created_at || strtotime((string) $existing->created_at) === false || strtotime((string) $existing->created_at) < strtotime((string) $order->created_at))) {
                        throw new RuntimeException("Stale existing target record for {$barcode}.");
                    }
                }
                if ($a->isNotEmpty() && ((string) $a->first()->serial_no !== $serial || $a->first()->series_key !== $seriesKey)) {
                    throw new RuntimeException("Allocation series conflict for {$barcode}.");
                }
                if ($s->isNotEmpty() && ($s->first()->provider_batch_id !== $local->provider_batch_id || $s->first()->article_type !== 'BUSINESS_PARCEL')) {
                    throw new RuntimeException("Existing booking identity conflict for {$barcode}.");
                }
                // Use the original local booking row's creation time as reconstruction evidence;
                // the exact original allocation time is unavailable. Never invent provider references.
                if ($a->isEmpty()) DB::table('indiapost_awb_allocations')->insert($identity + [
                    'order_line_id' => $lineId, 'serial_no' => $serial, 'series_key' => $seriesKey, 'created_at' => $local->created_at,
                ]);
                if ($s->isEmpty()) DB::table('indiapost_shipments')->insert($identity + [
                    'article_type' => 'BUSINESS_PARCEL', 'booking_status' => $local->booking_status,
                    'provider_batch_id' => $local->provider_batch_id,
                    'latest_event' => $local->latest_event ?? null, 'latest_event_at' => $local->latest_event_at ?? null,
                    'created_at' => $local->created_at, 'updated_at' => $local->updated_at ?? $local->created_at,
                ]);
                if ($l->isEmpty()) DB::table('indiapost_order_line_links')->insert($identity + [
                    'order_line_id' => $lineId, 'created_at' => $local->created_at,
                ]);
            }
            // Never rewind the existing shared serial counter. A full range remains
            // exhausted (end + 1); do not restart from the beginning.
            $next = max(self::SERIES_START, $maxSerial + 1, $counter ? (int) $counter->next_serial : 0);
            if (!$counter) {
                DB::table('indiapost_awb_counters')->insert(['series_key' => $seriesKey, 'next_serial' => $next]);
            } elseif ((int) $counter->next_serial < $next) {
                DB::table('indiapost_awb_counters')->where('series_key', $seriesKey)->update(['next_serial' => $next]);
            }
        }, 3);
    }

    private function highestRecordedSerial(): int
    {
        $sources = [
            'indiapost_order_shipments' => ['barcode_no'],
            'indiapost_shipments' => ['barcode_no'],
            'indiapost_awb_allocations' => ['barcode_no'],
            'indiapost_order_line_links' => ['barcode_no'],
            // Optional operational evidence; use supported columns only.
            'indiapost_tracking_events' => ['barcode_no', 'article_number', 'barcode', 'awb'],
            'indiapost_webhook_events' => ['article_number', 'barcode_no', 'barcode', 'awb'],
        ];
        $max = self::SERIES_START - 1;
        foreach ($sources as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) continue;
                $barcodes = DB::table($table)->where($column, 'like', self::SERIES_PREFIX . '%IN')
                    ->distinct()->pluck($column);
                foreach ($barcodes as $value) {
                    $barcode = strtoupper(trim((string) $value));
                    if (!preg_match('/^' . self::SERIES_PREFIX . '[0-9]{9}IN$/D', $barcode)) continue;
                    $serial = (int) substr($barcode, 2, 8);
                    if ($serial >= self::SERIES_START && $serial <= self::SERIES_END) {
                        // Reserve the serial even if a historical record has an invalid checksum.
                        $max = max($max, $serial);
                    }
                }
            }
        }
        return $max;
    }

    private function relatedRows(string $table, array $barcodes)
    {
        return DB::table($table)->where(function ($query) use ($barcodes) {
            $query->where('order_id', self::ORDER_ID)->orWhereIn('barcode_no', $barcodes);
        })->lockForUpdate()->get();
    }

    private function validateBarcode(string $barcode): void
    {
        if (!preg_match('/^[A-Z]{2}[0-9]{9}IN$/D', $barcode)) throw new RuntimeException('Invalid AWB format.');
        $weights = [8, 6, 4, 2, 3, 5, 9, 7];
        $sum = 0;
        foreach ($weights as $i => $weight) $sum += (int) $barcode[$i + 2] * $weight;
        $digit = 11 - $sum % 11;
        $digit = $digit === 10 ? 0 : ($digit === 11 ? 5 : $digit);
        if ((int) $barcode[10] !== $digit) throw new RuntimeException('Invalid AWB checksum.');
    }

    public function down(): void
    {
        // These are externally submitted AWBs. Deleting mappings or rewinding the counter
        // could enable duplicate bookings. Manual, audited reconciliation is required instead.
        throw new RuntimeException('AWB recovery is intentionally not automatically reversible.');
    }
};
