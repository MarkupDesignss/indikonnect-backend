<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('indiapost_order_shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique(); // v1: one parcel per order
            $table->unsignedBigInteger('warehouse_id');
            $table->string('barcode_no', 13)->unique();
            $table->string('booking_status', 40)->default('submitting');
            $table->unsignedInteger('weight_grams');
            $table->decimal('length_cm', 8, 2);
            $table->decimal('width_cm', 8, 2);
            $table->decimal('height_cm', 8, 2);
            $table->string('provider_batch_id', 120)->nullable();
            $table->string('latest_event', 160)->nullable();
            $table->string('latest_event_at', 60)->nullable();
            $table->boolean('provider_accepted')->default(false);
            $table->timestamp('booking_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index('booking_status');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('indiapost_order_shipments');
    }
};
