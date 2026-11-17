<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status ENUM(
                'return_rejected',
                'return_approved',
                'return_received',
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
                'cancel_pending',
                'cancel_rejected',
                'replaced',
                'return_pending',
                'partial_return_pending',
                'returned',
                'partial_returned',
                'refunded',
                'undelivered',
                'failed',
                'payment_failed',
                ''
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status ENUM(
                'return_rejected',
                'return_approved',
                'return_received',
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
                'failed',
                'payment_failed',
                ''
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};
