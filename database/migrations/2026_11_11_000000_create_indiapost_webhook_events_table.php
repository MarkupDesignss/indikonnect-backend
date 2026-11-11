<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('indiapost_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_hash', 64)->unique();
            $table->string('article_number', 32)->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->string('event_code', 100)->nullable();
            $table->string('event_description', 250)->nullable();
            $table->string('event_office_facility_id', 60)->nullable();
            $table->string('event_office_name', 250)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->string('mapped_status', 40)->nullable();
            $table->string('booking_ref_id', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indiapost_webhook_events');
    }
};
