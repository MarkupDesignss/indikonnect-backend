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
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('coupon_code')->nullable()->after('total_tax');
            $table->decimal('coupon_discount', 10, 2)->default(0)->after('coupon_code');
            $table->decimal('shipping_charge', 10, 2)->default(0)->after('coupon_discount');
            $table->decimal('subtotal_after_discount', 10, 2)->default(0)->after('shipping_charge');
            $table->decimal('total_payable', 10, 2)->default(0)->after('total');
            $table->json('summary_snapshot')->nullable()->after('total_payable');
            $table->string('pdf_path')->nullable()->after('summary_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            //
        });
    }
};
