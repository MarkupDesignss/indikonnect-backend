<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First check if there's any foreign key or index and drop them
        Schema::table('users', function (Blueprint $table) {
            // Check and drop foreign key if exists
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'users' 
                AND COLUMN_NAME = 'sponsor_id' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }

            // Check and drop index if exists
            $indexes = DB::select("
                SELECT INDEX_NAME 
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'users' 
                AND COLUMN_NAME = 'sponsor_id'
            ");

            foreach ($indexes as $index) {
                try {
                    $table->dropIndex($index->INDEX_NAME);
                } catch (\Exception $e) {
                    // Index might already be dropped
                }
            }
        });

        // Now change the column type
        Schema::table('users', function (Blueprint $table) {
            $table->string('sponsor_id', 191)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert back to integer
            $table->integer('sponsor_id')->nullable()->change();
        });
    }
};