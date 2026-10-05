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
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();

            $table->integer('quantity')->default(0);

            $table->timestamps();

            // Foreign Keys
            $table->foreign('warehouse_id')
                ->references('id')->on('warehouses')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->foreign('variant_id')
                ->references('id')->on('product_variants')
                ->onDelete('cascade');

            // Unique
            $table->unique(
                ['warehouse_id', 'product_id', 'variant_id'],
                'unique_warehouse_product_variant'
            );

            // Indexes
            $table->index('warehouse_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
