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
        // 1. Fix Engines to InnoDB
        DB::statement('ALTER TABLE genealogy_placements ENGINE=InnoDB');
        DB::statement('ALTER TABLE audit_logs ENGINE=InnoDB');
        DB::statement('ALTER TABLE consent_records ENGINE=InnoDB');
        
        // 2. Add Foreign Keys for genealogy_placements
        Schema::table('genealogy_placements', function (Blueprint $table) {
            // user_id FK
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
        
        // 3. Add Foreign Key for audit_logs
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
        
        // 4. Add Foreign Key for consent_records
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