<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // MariaDB/MySQL ALTER TABLE is DDL and is not transactionally reversible.
    public $withinTransaction = false;

    private const DATABASE = 'markcawm_indikonnect';
    private const CORE_TABLES = ['orders', 'order_lines'];
    private const COURIER_TABLES = [
        'indiapost_order_shipments',
        'indiapost_shipments',
        'indiapost_awb_allocations',
        'indiapost_order_line_links',
        'indiapost_awb_counters',
    ];

    public function up(): void
    {
        if ((string) DB::connection()->getDatabaseName() !== self::DATABASE) {
            throw new RuntimeException('Unexpected database; engine migration stopped.');
        }

        // Preflight all required tables before making any DDL changes.
        $engines = [];
        foreach (array_merge(self::CORE_TABLES, self::COURIER_TABLES) as $table) {
            $engines[$table] = $this->engine($table);
            if (in_array($table, self::CORE_TABLES, true)) {
                if ($engines[$table] !== 'INNODB') {
                    throw new RuntimeException("Core table {$table} is not InnoDB; no automatic changes allowed.");
                }
            } elseif (!in_array($engines[$table], ['INNODB', 'MYISAM', 'ARIA'], true)) {
                throw new RuntimeException("Unexpected engine on {$table}: {$engines[$table]}; stopped.");
            }
        }

        // Only courier tables with an explicitly supported non-InnoDB engine are converted.
        // Keep all booking, allocation and webhook writers paused until both migrations finish.
        foreach (self::COURIER_TABLES as $table) {
            if ($this->engine($table) === 'INNODB') {
                continue;
            }
            if ($this->engine($table) !== $engines[$table]) {
                throw new RuntimeException("Engine changed during preflight for {$table}; stopped.");
            }
            // Table names are fixed constants, not user inputs.
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
            if ($this->engine($table) !== 'INNODB') {
                throw new RuntimeException("Engine conversion could not be verified for {$table}.");
            }
        }

        foreach (array_merge(self::CORE_TABLES, self::COURIER_TABLES) as $table) {
            if ($this->engine($table) !== 'INNODB') {
                throw new RuntimeException("Final engine verification failed for {$table}.");
            }
        }
    }

    private function engine(string $table): string
    {
        $row = DB::selectOne(
            'SELECT ENGINE AS storage_engine, TABLE_TYPE AS table_kind FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        if (!$row || strtoupper((string) ($row->table_kind ?? '')) !== 'BASE TABLE' || empty($row->storage_engine)) {
            throw new RuntimeException("Missing or unsupported table {$table}; stopped.");
        }
        return strtoupper((string) $row->storage_engine);
    }

    public function down(): void
    {
        throw new RuntimeException('Do not revert courier tables to non-transactional engines. Restore only from an audited backup if necessary.');
    }
};
