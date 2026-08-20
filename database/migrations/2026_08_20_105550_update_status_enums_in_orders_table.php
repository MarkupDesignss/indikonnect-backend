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
            ALTER TABLE orders
            MODIFY COLUMN status ENUM(
                'pending',
                'confirmed',
                'processing',
                'dispatched',
                'shipped',
                'partial_delivered',
                'delivered',
                'cancelled',
                'returned'
            ) DEFAULT 'pending'
        ");

        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN return_status ENUM(
                'none',
                'pending',
                'partial_pending',
                'approved',
                'partial_approved',
                'fully_approved',
                'rejected',
                'partial_rejected',
                'returned',
                'partial_returned',
                'fully_returned'
            ) DEFAULT 'none'
        ");
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
};