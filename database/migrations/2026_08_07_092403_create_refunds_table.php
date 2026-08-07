<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // Links to the original order
            $table->foreignId('order_id')->constrained()->onDelete('cascade');

            // Links back to the return request (if refund came from return)
            $table->foreignId('return_id')->nullable()->constrained()->onDelete('set null');

            // Amount being refunded (including GST)
            $table->decimal('amount', 12, 2);

            // Payment gateway reference (for reconciliation)
            $table->string('gateway_reference')->nullable();

            // Status of the refund transaction
            $table->enum('status', ['initiated', 'completed', 'failed'])->default('initiated');

            // Timestamp when refund is completed
            $table->timestamp('completed_at')->nullable();

            // Reason if refund failed
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // Performance indexes
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};