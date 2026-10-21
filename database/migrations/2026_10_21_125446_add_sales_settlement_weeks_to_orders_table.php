<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 1. Drop parent_order_id (no longer needed)
            if (Schema::hasColumn('orders', 'parent_order_id')) {
                $table->dropColumn('parent_order_id');
            }

            // 2. Add sales/settlement week columns
            if (!Schema::hasColumn('orders', 'sales_week_year')) {
                $table->smallInteger('sales_week_year')->nullable()->after('ignite_sync_error');
            }
            if (!Schema::hasColumn('orders', 'sales_week_no')) {
                $table->smallInteger('sales_week_no')->nullable()->after('sales_week_year');
            }
            if (!Schema::hasColumn('orders', 'settlement_week_year')) {
                $table->smallInteger('settlement_week_year')->nullable()->after('sales_week_no');
            }
            if (!Schema::hasColumn('orders', 'settlement_week_no')) {
                $table->smallInteger('settlement_week_no')->nullable()->after('settlement_week_year');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Re-add parent_order_id (rollback)
            if (!Schema::hasColumn('orders', 'parent_order_id')) {
                $table->unsignedBigInteger('parent_order_id')->nullable()->after('id');
            }

            // Drop the week columns
            $table->dropColumn([
                'sales_week_year',
                'sales_week_no',
                'settlement_week_year',
                'settlement_week_no',
            ]);
        });
    }
};