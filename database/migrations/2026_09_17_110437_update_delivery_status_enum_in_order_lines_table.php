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
        Schema::table('order_lines', function (Blueprint $table) {
            $table->enum('delivery_status', [
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
                'refunded',

                // Buyback statuses
                'buyback_pending',
                'buyback_approved',
                'buyback_rejected',
                'buyback_refunded',
                'buyback_cancelled',
            ])->change();
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
