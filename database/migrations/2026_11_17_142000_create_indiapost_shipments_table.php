<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('indiapost_shipments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('order_reference', 100)->nullable();
            $table->char('barcode_no', 13)->index();
            $table->string('article_type', 40);
            $table->string('booking_status', 40)->default('submitting')->index();
            $table->string('latest_event', 160)->nullable();
            $table->string('latest_event_at', 60)->nullable();
            $table->string('provider_batch_id', 120)->nullable();
            $table->string('provider_booking_ref', 120)->nullable();
            $table->decimal('tariff_amount', 12, 2)->nullable();
            $table->longText('provider_response_json')->nullable();
            $table->datetime('created_at', 3)->useCurrent();
            $table->datetime('updated_at', 3)
                ->useCurrent()
                ->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indiapost_shipments');
    }
};