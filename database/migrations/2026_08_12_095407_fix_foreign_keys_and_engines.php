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
        // ==========================================
        // 1. FIRST: Fix Engines to InnoDB
        // ==========================================
        DB::statement('ALTER TABLE genealogy_placements ENGINE=InnoDB');
        DB::statement('ALTER TABLE audit_logs ENGINE=InnoDB');
        DB::statement('ALTER TABLE consent_records ENGINE=InnoDB');

        // ==========================================
        // 2. SECOND: Clean orphaned records first
        // ==========================================
        // Delete any records with invalid user_id
        DB::table('genealogy_placements')
            ->whereNotIn('user_id', DB::table('users')->pluck('id'))
            ->delete();
        
        DB::table('audit_logs')
            ->whereNotIn('user_id', DB::table('users')->pluck('id'))
            ->delete();
        
        DB::table('consent_records')
            ->whereNotIn('user_id', DB::table('users')->pluck('id'))
            ->delete();

        // ==========================================
        // 3. THIRD: Drop existing foreign keys if any
        // ==========================================
        try {
            Schema::table('genealogy_placements', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Exception $e) {
            // Foreign key doesn't exist, continue
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Exception $e) {
            // Foreign key doesn't exist, continue
        }

        try {
            Schema::table('consent_records', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Exception $e) {
            // Foreign key doesn't exist, continue
        }

        // ==========================================
        // 4. FOURTH: Add Foreign Keys
        // ==========================================
        Schema::table('genealogy_placements', function (Blueprint $table) {
            // Make sure column types match
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('genealogy_placements', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};