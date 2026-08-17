<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // Refund breakdown
            $table->decimal('refund_subtotal', 12, 2)->default(0)->after('reason');
            $table->decimal('refund_tax', 12, 2)->default(0)->after('refund_subtotal');
            $table->decimal('refund_shipping', 12, 2)->default(0)->after('refund_tax');
            $table->decimal('total_refund_amount', 12, 2)->default(0)->after('refund_shipping');
            
            // Commissionable Volume reversal
            $table->decimal('total_cv_reversed', 12, 2)->default(0)->after('total_refund_amount');
            
            // Admin rejection reason (separate from admin_notes)
            $table->text('rejection_reason')->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn([
                'refund_subtotal',
                'refund_tax',
                'refund_shipping',
                'total_refund_amount',
                'total_cv_reversed',
                'rejection_reason',
            ]);
        });
    }
};