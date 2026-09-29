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
        // Update order_lines.delivery_status
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN delivery_status ENUM(
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
                'return_received',
                'return_rejected',
                'returned',
                'replaced',
                'refunded',
                'buyback_pending',
                'buyback_approved',
                'buyback_rejected',
                'buyback_refunded',
                'buyback_cancelled'
            ) NULL DEFAULT NULL
        ");

        // Update orders.status
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status ENUM(
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
                'return_pending',
                'partial_return_pending',
                'returned',
                'partial_returned',
                'refunded'
            ) NOT NULL DEFAULT 'pending'
        ");
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN return_status ENUM(
                'none',
                'pending',
                'approved',
                'rejected',
                'received',
                'returned',
                'replaced',         
                'refunded'
            ) NULL DEFAULT 'none'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};