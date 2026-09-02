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
        Schema::table('credit_notes', function (Blueprint $table) {
            // ============================================
            // BUYER DETAILS (Required for GST Compliance)
            // ============================================
            $table->string('buyer_name')->after('refund_id');
            $table->string('buyer_email')->nullable()->after('buyer_name');
            $table->text('buyer_address')->nullable()->after('buyer_email');
            $table->string('buyer_state', 100)->nullable()->after('buyer_address');
            $table->string('buyer_gstin', 50)->nullable()->after('buyer_state');
            
            // ============================================
            // TAX BREAKDOWN (Required for GST Credit Note)
            // ============================================
            $table->decimal('taxable_value', 12, 2)->default(0.00)->after('amount');
            $table->decimal('cgst_amount', 12, 2)->default(0.00)->after('taxable_value');
            $table->decimal('sgst_amount', 12, 2)->default(0.00)->after('cgst_amount');
            $table->decimal('igst_amount', 12, 2)->default(0.00)->after('sgst_amount');
            $table->decimal('total_gst', 12, 2)->default(0.00)->after('igst_amount');
            
            // ============================================
            // ITEMS & TYPE (Business Rules)
            // ============================================
            $table->json('items')->nullable()->after('total_gst');
            $table->enum('buyer_type', ['customer', 'distributor'])->default('customer')->after('items');
            
            // ============================================
            // ADD INDEXES for better performance
            // ============================================
            $table->index('buyer_email');
            $table->index('issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_name',
                'buyer_email',
                'buyer_address',
                'buyer_state',
                'buyer_gstin',
                'taxable_value',
                'cgst_amount',
                'sgst_amount',
                'igst_amount',
                'total_gst',
                'items',
                'buyer_type',
            ]);
        });
    }
};