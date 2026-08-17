<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // Original / MRP prices
            $table->decimal('retail_mrp', 12, 2)
                ->after('retail_price');

            $table->decimal('distributor_mrp', 12, 2)
                ->nullable()
                ->after('distributor_price');

            // Retail product-level discount
            $table->enum('retail_discount_type', ['percentage', 'fixed'])
                ->nullable()
                ->after('retail_mrp');

            $table->decimal('retail_discount_value', 12, 2)
                ->nullable()
                ->after('retail_discount_type');

            // Distributor product-level discount
            $table->enum('distributor_discount_type', ['percentage', 'fixed'])
                ->nullable()
                ->after('distributor_mrp');

            $table->decimal('distributor_discount_value', 12, 2)
                ->nullable()
                ->after('distributor_discount_type');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'retail_mrp',
                'distributor_mrp',
                'retail_discount_type',
                'retail_discount_value',
                'distributor_discount_type',
                'distributor_discount_value',
            ]);
        });
    }
};