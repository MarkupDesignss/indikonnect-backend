<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');                         // e.g., Standard, Express
            $table->string('code')->unique();               // e.g., standard, express
            $table->text('description')->nullable();
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->enum('rate_type', ['flat', 'percentage', 'free'])->default('flat');
            $table->decimal('rate_value', 10, 2)->nullable(); // For percentage
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->decimal('max_order_amount', 10, 2)->nullable();
            $table->integer('estimated_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Insert default methods
        DB::table('shipping_methods')->insert([
            [
                'name' => 'Standard Delivery',
                'code' => 'standard',
                'description' => 'Delivered within 5-7 business days',
                'base_rate' => 50.00,
                'rate_type' => 'flat',
                'rate_value' => null,
                'min_order_amount' => null,
                'max_order_amount' => null,
                'estimated_days' => 7,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Express Delivery',
                'code' => 'express',
                'description' => 'Delivered within 2-3 business days',
                'base_rate' => 100.00,
                'rate_type' => 'flat',
                'rate_value' => null,
                'min_order_amount' => null,
                'max_order_amount' => null,
                'estimated_days' => 3,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Free Shipping',
                'code' => 'free',
                'description' => 'Free delivery on orders above ₹500',
                'base_rate' => 0.00,
                'rate_type' => 'free',
                'rate_value' => null,
                'min_order_amount' => 500.00,
                'max_order_amount' => null,
                'estimated_days' => 7,
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};