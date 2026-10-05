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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('name', 255);
            $table->string('code', 50)->unique();

            // Address (required for shipping)
            $table->string('address_line_1', 255);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('pincode', 10);
            $table->string('country', 100)->default('India');

            // Contact (required for communication)
            $table->string('contact_person', 255);
            $table->string('contact_number', 20);

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            // ============================================
            // OPTIONAL FIELDS (Business Needs)
            // ============================================

            // Extended Address
            $table->string('address_line_2', 255)->nullable();

            // Extended Contact
            $table->string('contact_email', 255)->nullable();

            // Capacity (useful for large warehouses)
            $table->integer('total_capacity')->nullable(); // Max items it can hold

            // Working Hours (optional)
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();

            // ============================================
            // TRACKING
            // ============================================
            $table->timestamps();
            $table->softDeletes();

            // ============================================
            // INDEXES
            // ============================================
            $table->index('is_active');
            $table->index('is_default');
            $table->index('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
