<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('order_lines', 'ignite_order_no')) {
                $table->string('ignite_order_no')->nullable()->after('item_reference_id');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_sync_status')) {
                $table->string('ignite_sync_status')->nullable()->after('ignite_order_no');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_sync_error')) {
                $table->text('ignite_sync_error')->nullable()->after('ignite_sync_status');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_sales_week_year')) {
                $table->smallInteger('ignite_sales_week_year')->nullable()->after('ignite_sync_error');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_sales_week_no')) {
                $table->smallInteger('ignite_sales_week_no')->nullable()->after('ignite_sales_week_year');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_settlement_week_year')) {
                $table->smallInteger('ignite_settlement_week_year')->nullable()->after('ignite_sales_week_no');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_settlement_week_no')) {
                $table->smallInteger('ignite_settlement_week_no')->nullable()->after('ignite_settlement_week_year');
            }
            if (!Schema::hasColumn('order_lines', 'ignite_cv_posted')) {
                $table->decimal('ignite_cv_posted', 15, 2)->nullable()->after('ignite_settlement_week_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn([
                'ignite_order_no', 'ignite_sync_status', 'ignite_sync_error',
                'ignite_sales_week_year', 'ignite_sales_week_no',
                'ignite_settlement_week_year', 'ignite_settlement_week_no',
                'ignite_cv_posted',
            ]);
        });
    }
};