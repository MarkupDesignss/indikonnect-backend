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
            MODIFY delivery_status ENUM(
                'pending',
                'confirmed',
                'shipped',
                'dispatched',
                'delivered',
                'cancel_pending',
                'cancel_rejected',
                'cancelled',
                'return_initiated',
                'return_pending',
                'return_approved',
                'return_rejected',
                'returned',
                'refunded'
            ) NOT NULL DEFAULT 'pending'
        ");

        Schema::table('order_lines', function (Blueprint $table) {
            $table->timestamp('cancellation_requested_at')
                ->nullable()
                ->after('delivery_status');
        });
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
