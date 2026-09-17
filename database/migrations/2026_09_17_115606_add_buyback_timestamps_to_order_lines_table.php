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
            $table->timestamp('buyback_requested_at')
                ->nullable()
                ->after('line_total');

            $table->timestamp('buyback_approved_at')
                ->nullable()
                ->after('buyback_requested_at');

            $table->timestamp('buyback_rejected_at')
                ->nullable()
                ->after('buyback_approved_at');

            $table->timestamp('buyback_refunded_at')
                ->nullable()
                ->after('buyback_rejected_at');
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