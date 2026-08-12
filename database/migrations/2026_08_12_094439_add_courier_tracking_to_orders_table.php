<?php
// database/migrations/2026_08_12_000004_add_courier_tracking_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_company')->nullable()->after('status');
            $table->string('courier_tracking_number')->nullable()->after('courier_company');
            $table->string('courier_status')->nullable()->after('courier_tracking_number');
            $table->timestamp('courier_delivery_date')->nullable()->after('courier_status');
            $table->text('delivery_notes')->nullable()->after('courier_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'courier_company',
                'courier_tracking_number',
                'courier_status',
                'courier_delivery_date',
                'delivery_notes'
            ]);
        });
    }
};