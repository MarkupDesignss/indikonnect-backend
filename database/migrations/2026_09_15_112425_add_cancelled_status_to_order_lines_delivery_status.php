<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN delivery_status ENUM(
                'none',
                'pending',
                'approved',
                'rejected',
                'returned',
                'cancelled'
            ) NOT NULL DEFAULT 'none'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            //
        });
    }
};
