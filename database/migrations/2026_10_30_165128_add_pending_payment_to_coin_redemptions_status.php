<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE coin_redemptions 
            MODIFY COLUMN status ENUM(
                'pending',
                'pending_payment',
                'authorized',
                'used',
                'failed',
                'expired',
                'reversed',
                'retry',
                'retry_needed',
                'ignite_refused',
                'ignite_deducted_no_amount',
                'ignite_deducted_no_rate'
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE coin_redemptions 
            MODIFY COLUMN status ENUM(
                'pending',
                'authorized',
                'used',
                'failed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};