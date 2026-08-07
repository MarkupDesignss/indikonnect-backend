<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_reference')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('billing_address_id')->constrained('addresses')->onDelete('restrict');
            $table->foreignId('delivery_address_id')->constrained('addresses')->onDelete('restrict');
            $table->enum('order_type', ['retail', 'distributor'])->default('retail');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total_gst', 12, 2);
            $table->decimal('shipping_charge', 12, 2)->default(0);
            $table->decimal('coin_redeemed', 12, 2)->default(0);
            $table->decimal('total_payable', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->enum('status', [
                'pending', 'confirmed', 'processing',
                'dispatched', 'delivered', 'cancelled', 'returned'
            ])->default('pending');
            $table->string('payment_gateway')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('tax_breakdown')->nullable(); // detailed GST per line

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes
            $table->index(['user_id', 'status']);
            $table->index('order_reference');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};