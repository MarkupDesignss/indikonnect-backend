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

        'replacement_pending',
        'replacement_approved',
        'replacement_rejected',
        'replaced',

        'refunded',

        'buyback_pending',
        'buyback_approved',
        'buyback_rejected',
        'buyback_refunded',
        'buyback_cancelled'
    ) NULL DEFAULT NULL
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
