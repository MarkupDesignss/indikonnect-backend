<?php
// database/migrations/2026_08_12_000006_fix_foreign_keys_and_engines.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        // 1. Add indexes on user_id for all 3 tables
        Schema::table('genealogy_placements', function (Blueprint $table) {
            if (!Schema::hasIndex('genealogy_placements', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasIndex('audit_logs', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('consent_records', function (Blueprint $table) {
            if (!Schema::hasIndex('consent_records', ['user_id'])) {
                $table->index('user_id');
            }
        });

        // 2. Try to convert to InnoDB (optional, won't break if fails)
        try {
            DB::statement('ALTER TABLE genealogy_placements ENGINE=InnoDB');
            DB::statement('ALTER TABLE audit_logs ENGINE=InnoDB');
            DB::statement('ALTER TABLE consent_records ENGINE=InnoDB');
        } catch (\Exception $e) {
            // Ignore - MyISAM is fine
        }
    }

    public function down(): void
    {
        Schema::table('genealogy_placements', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};