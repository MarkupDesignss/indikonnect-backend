<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
                'undelivered',
                'failed'
            ) NOT NULL DEFAULT 'pending'
        ");

        DB::statement("
            ALTER TABLE orders
            MODIFY status ENUM(
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
                'undelivered',
                'failed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // Restore the previous ENUM values here if rollback is required.
    }
};