<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
                'pending',
                'shipped',
                'delivered',
                'cancelled',
                 'return_initiated',
                'return_pending',
                'return_approved',
                 'return_rejected',
                'returned'
            ) DEFAULT 'pending'
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