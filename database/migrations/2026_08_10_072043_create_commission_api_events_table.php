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
        Schema::create('commission_api_events', function (Blueprint $table) {
            $table->id();
            $table->enum('event_type', ['order_post', 'reversal', 'payout_release', 'coin_redemption']);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->enum('status', ['pending', 'sent', 'acknowledged', 'failed', 'retrying'])->default('pending');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->unsignedTinyInteger('max_retries')->default(5);
            $table->timestamp('last_attempt')->nullable();
            $table->text('error_message')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_api_events');
    }
};
