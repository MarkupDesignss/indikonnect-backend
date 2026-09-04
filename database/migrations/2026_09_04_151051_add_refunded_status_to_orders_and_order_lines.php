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
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'confirmed',
                'processing',
                'dispatched',
                'partial_dispatched',
                'shipped',
                'partial_shipped',
                'partial_delivered',
                'delivered',
                'cancelled',
                'returned',
                'partial_returned',
                'partial_return',
                'refunded',
            ])->default('pending')->change();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->enum('delivery_status', [
                'pending',
                'confirmed',
                'shipped',
                'dispatched',
                'delivered',
                'cancelled',
                'return_initiated',
                'return_pending',
                'return_approved',
                'return_rejected',
                'returned',
                'refunded',
            ])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_and_order_lines', function (Blueprint $table) {
            //
        });
    }
};
