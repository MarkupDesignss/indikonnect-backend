<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update order_lines.delivery_status ENUM
        DB::statement("
            ALTER TABLE `order_lines`
            MODIFY COLUMN `delivery_status` ENUM(
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

                'replacement_pending',
                'replacement_approved',
                'replacement_rejected',
                'replaced',

                'refunded',

                'buyback_pending',
                'buyback_approved',
                'buyback_rejected',
                'buyback_refunded',
                'buyback_cancelled',

                'undelivered'
            )
            NOT NULL DEFAULT 'pending'
        ");

        // Update orders.status ENUM
        DB::statement("
            ALTER TABLE `orders`
            MODIFY COLUMN `status` ENUM(
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

                'refunded',

                'undelivered'
            )
            NOT NULL DEFAULT 'pending'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore order_lines.delivery_status ENUM
        DB::statement("
            ALTER TABLE `order_lines`
            MODIFY COLUMN `delivery_status` ENUM(
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
                'replacement_pending',
                'replacement_approved',
                'replacement_rejected',
                'replaced',
                'refunded',
                'buyback_pending',
                'buyback_approved',
                'buyback_rejected',
                'buyback_refunded',
                'buyback_cancelled',
                'undelivered'
            )
            NOT NULL DEFAULT 'pending'
        ");

        // Restore orders.status ENUM
        DB::statement("
            ALTER TABLE `orders`
            MODIFY COLUMN `status` ENUM(
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
                'refunded',
                'undelivered'
            )
            NOT NULL DEFAULT 'pending'
        ");
    }
};
