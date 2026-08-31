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
            // Tax Rates
            $table->decimal('cgst_rate', 8, 2)
                ->default(0)
                ->after('gst_rate');

            $table->decimal('sgst_rate', 8, 2)
                ->default(0)
                ->after('cgst_rate');

            $table->decimal('igst_rate', 8, 2)
                ->default(0)
                ->after('sgst_rate');

            // Tax Amounts
            $table->decimal('cgst_amount', 15, 2)
                ->default(0)
                ->after('gst_amount');

            $table->decimal('sgst_amount', 15, 2)
                ->default(0)
                ->after('cgst_amount');

            $table->decimal('igst_amount', 15, 2)
                ->default(0)
                ->after('sgst_amount');
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
