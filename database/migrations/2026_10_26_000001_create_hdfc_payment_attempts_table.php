<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hdfc_payment_attempts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');

            $table->string('gateway', 16);
            $table->string('gateway_order_id', 20)->unique();
            $table->string('session_id')->nullable();
            $table->text('payment_link')->nullable();
            $table->string('status', 32)->default('creating');
            $table->string('gateway_status', 64)->nullable();
            $table->string('transaction_id', 128)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('INR');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hdfc_payment_attempts');
    }
};
