<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                 // e.g., SAVE10
            $table->string('title')->nullable();              // e.g., "Summer Sale 10%"
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('value', 12, 2);                  // 10 (for 10%) or 100 (fixed)
            $table->decimal('min_order', 12, 2)->nullable();  // Minimum order amount (e.g., 500)
            $table->decimal('max_order', 12, 2)->nullable();  // Maximum order amount (e.g., 5000) – optional
            $table->integer('max_uses')->nullable();          // How many times total
            $table->integer('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};