<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Product Variants Table
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('sku')->unique();
            $table->json('attributes')->comment('{"color":"Red","size":"M"}');
            $table->decimal('retail_price', 12, 2);
            $table->decimal('retail_mrp', 12, 2);
            $table->enum('retail_discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('retail_discount_value', 12, 2)->nullable();
            $table->decimal('distributor_price', 12, 2)->nullable();
            $table->decimal('distributor_mrp', 12, 2)->nullable();
            $table->enum('distributor_discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('distributor_discount_value', 12, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['product_id', 'is_active']);
            $table->index('sku');
        });

        // 2. Variant Images Table
        Schema::create('variant_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->onDelete('cascade');
            $table->string('image');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['variant_id', 'is_primary']);
        });

        // 3. Add variant_id to cart_items
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->onDelete('set null');
            $table->index('variant_id');
        });

        // 4. Add variant_id to order_lines
        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->onDelete('set null');
            $table->index('variant_id');
        });

        // 5. Add variant_id to stock_movements
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->onDelete('set null');
            $table->index('variant_id');
        });

        // 6. Add variant_id to wishlists (optional)
        Schema::table('wishlists', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->onDelete('set null');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });

        Schema::dropIfExists('variant_images');
        Schema::dropIfExists('product_variants');
    }
};