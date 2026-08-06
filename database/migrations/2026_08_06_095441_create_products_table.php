<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('specification')->nullable();
            $table->foreignId('category_id')->constrained()->onDelete('restrict');
            $table->foreignId('tax_category_id')->nullable()->constrained('tax_categories')->onDelete('restrict');
            $table->decimal('retail_price', 12, 2);
            $table->decimal('distributor_price', 12, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->boolean('is_published')->default(false);
            $table->json('images')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('product_code');
            $table->index('is_published');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};