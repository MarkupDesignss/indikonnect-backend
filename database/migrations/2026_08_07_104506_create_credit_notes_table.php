<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            // Unique, sequential, gapless number (generated in code)
            $table->string('credit_note_number')->unique();

            // Links to the original order
            $table->foreignId('order_id')->constrained()->onDelete('cascade');

            // Reference to the original invoice (snapshot)
            $table->string('original_invoice_number');

            // Link to refund (if you have refunds table)
            $table->foreignId('refund_id')->nullable()->constrained()->onDelete('set null');

            // Amount credited (including GST)
            $table->decimal('amount', 12, 2);

            // Reason for credit note (e.g., 'return', 'cancellation', 'cooling-off', 'buy-back')
            $table->string('reason')->nullable();

            // Issued when refund is initiated
            $table->timestamp('issued_at');

            $table->timestamps();

            // Performance indexes
            $table->index(['credit_note_number', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};