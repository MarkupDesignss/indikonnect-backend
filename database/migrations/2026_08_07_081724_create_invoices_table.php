<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Sequential, gapless invoice number
            $table->string('invoice_number')->unique();

            // Link to order
            $table->foreignId('order_id')->constrained()->onDelete('cascade');

            // SUPPLIER DETAILS – Snapshot from settings (auto-fetched)
            $table->string('seller_name');
            $table->string('seller_gstin')->nullable();
            $table->string('seller_address')->nullable();

            // BUYER DETAILS – Snapshot from order (user profile at time of purchase)
            $table->string('buyer_name');
            $table->string('buyer_gstin')->nullable();
            $table->string('buyer_address');

            // GST jurisdiction (delivery state)
            $table->string('delivery_state');

            // FR-CO-003: Itemised GST breakdown (JSON)
            $table->json('line_items');
            /*
                Each line:
                {
                    product_name: ...,
                    quantity: ...,
                    unit_price: ...,
                    taxable_value: ...,
                    gst_rate: ...,
                    cgst: ...,
                    sgst: ...,
                    igst: ...,
                    line_total: ...
                }
            */

            // FR-CO-004: Coin redemption does NOT reduce taxable value
            $table->decimal('subtotal_before_redemption', 12, 2);
            $table->decimal('coin_redeemed', 12, 2)->default(0);

            // Totals (for quick display & reporting)
            $table->decimal('total_taxable', 12, 2);
            $table->decimal('total_cgst', 12, 2)->default(0);
            $table->decimal('total_sgst', 12, 2)->default(0);
            $table->decimal('total_igst', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2);
            $table->decimal('total', 12, 2); // final payable after coin redemption

            // Issued at timestamp
            $table->timestamp('issued_at');

            $table->timestamps();

            $table->index(['invoice_number', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};