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
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();

            $table->string('proforma_invoice_number')->unique();

            $table->unsignedBigInteger('order_id')->nullable()->index();

            $table->string('seller_name')->nullable();
            $table->string('seller_gstin')->nullable();
            $table->text('seller_address')->nullable();

            $table->string('buyer_name')->nullable();
            $table->string('buyer_gstin')->nullable();
            $table->text('buyer_address')->nullable();

            $table->string('delivery_state')->nullable();

            $table->json('line_items')->nullable();

            $table->decimal('subtotal_before_redemption', 15, 2)->default(0);
            $table->decimal('coin_redeemed', 15, 2)->default(0);

            $table->decimal('total_taxable', 15, 2)->default(0);

            $table->decimal('total_cgst', 15, 2)->default(0);
            $table->decimal('total_sgst', 15, 2)->default(0);
            $table->decimal('total_igst', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);

            $table->string('coupon_code')->nullable();
            $table->decimal('coupon_discount', 15, 2)->default(0);

            $table->decimal('shipping_charge', 15, 2)->default(0);

            $table->decimal('subtotal_after_discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('total_payable', 15, 2)->default(0);

            $table->json('summary_snapshot')->nullable();

            $table->string('pdf_path')->nullable();

            $table->timestamp('issued_at')->nullable();

            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proforma_invoices');
    }
};
