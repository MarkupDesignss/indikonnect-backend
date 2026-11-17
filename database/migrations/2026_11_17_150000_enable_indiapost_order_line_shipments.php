<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MariaDB DDL commits implicitly.
    public $withinTransaction = false;

    public function up(): void
    {
        // Verify required tables exist, regardless of storage engine.
        $required = [
            'orders',
            'order_lines',
            'indiapost_order_shipments',
            'indiapost_awb_allocations',
            'indiapost_awb_counters',
            'indiapost_shipments',
            'indiapost_order_line_links',
            'order_shipping_details',
        ];

        $tables = DB::table('information_schema.TABLES')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->whereIn('TABLE_NAME', $required)
            ->pluck('TABLE_NAME')
            ->toArray();

        foreach ($required as $table) {
            if (!in_array($table, $tables, true)) {
                throw new \RuntimeException(
                    "Required table missing: {$table}. Stop and inspect; no ALTER executed."
                );
            }
        }

        // Verify prerequisite columns.
        if (
            !Schema::hasColumns('indiapost_awb_allocations', [
                'order_id',
                'order_line_id',
                'order_reference',
                'scope_line_id',
                'barcode_no',
            ])
            || !Schema::hasColumns('order_shipping_details', [
                'order_id',
                'order_line_id',
                'courier_tracking_number',
                'status',
            ])
        ) {
            throw new \RuntimeException(
                'Node Phase 3 or shipping details schema not ready; no ALTER executed.'
            );
        }

        // Read existing indexes.
        $indexes = DB::select("
            SELECT
                INDEX_NAME AS index_name,
                NON_UNIQUE AS non_unique,
                COLUMN_NAME AS column_name,
                SEQ_IN_INDEX AS seq
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'indiapost_order_shipments'
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");

        $unique = [];

        foreach ($indexes as $index) {
            if ((int) $index->non_unique === 0) {
                $unique[$index->index_name][] = $index->column_name;
            }
        }

        $legacy = 'indiapost_order_shipments_order_id_unique';
        $barcode = 'indiapost_order_shipments_barcode_no_unique';
        $scoped = 'uq_indiapost_local_order_scope';

        // Verify globally unique AWB index.
        if (($unique[$barcode] ?? null) !== ['barcode_no']) {
            throw new \RuntimeException(
                'Existing globally unique AWB index not verified; stop.'
            );
        }

        $hasLine = Schema::hasColumn(
            'indiapost_order_shipments',
            'order_line_id'
        );

        $hasScope = Schema::hasColumn(
            'indiapost_order_shipments',
            'scope_line_id'
        );

        // Handle an already completed ALTER.
        if (
            $hasLine
            && $hasScope
            && !isset($unique[$legacy])
            && ($unique[$scoped] ?? null) === ['order_id', 'scope_line_id']
        ) {
            $column = DB::selectOne("
                SELECT EXTRA AS extra
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'indiapost_order_shipments'
                  AND COLUMN_NAME = 'scope_line_id'
            ");

            if (
                !$column
                || stripos((string) $column->extra, 'GENERATED') === false
                || in_array(['order_id'], $unique, true)
            ) {
                throw new \RuntimeException(
                    'Unexpected scope column or order-only unique index; inspect manually.'
                );
            }

            return;
        }

        // Reject unexpected partial schema changes.
        if (
            $hasLine
            || $hasScope
            || isset($unique[$scoped])
            || ($unique[$legacy] ?? null) !== ['order_id']
            || count(array_filter(
                $unique,
                static fn($cols) => $cols === ['order_id']
            )) !== 1
        ) {
            throw new \RuntimeException(
                'Unexpected existing shipment schema. Inspect manually; no ALTER executed.'
            );
        }

        // Apply order-line-level shipment scope.
        DB::statement("
            ALTER TABLE indiapost_order_shipments
                ADD COLUMN order_line_id BIGINT UNSIGNED NULL AFTER order_id,
                ADD COLUMN scope_line_id BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IFNULL(order_line_id, 0)) STORED,
                ADD UNIQUE KEY uq_indiapost_local_order_scope
                    (order_id, scope_line_id),
                ADD INDEX ix_indiapost_local_line (order_line_id),
                DROP INDEX indiapost_order_shipments_order_id_unique
        ");
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Do not roll back shipment scope automatically; reconcile all AWBs '
                . 'and use coordinated code/database recovery.'
        );
    }
};
