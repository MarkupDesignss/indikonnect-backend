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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('shipping_charge', 10, 2)
                ->default(0)
                ->after('stock_quantity');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->decimal('shipping_charge', 10, 2)
                ->default(0)
                ->after('quantity');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->decimal('shipping_charge', 10, 2)
                ->default(0)
                ->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_cart_items_and_order_lines_tables', function (Blueprint $table) {
            //
        });
    }
};
