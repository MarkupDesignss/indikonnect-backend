<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // Order line link (multi-item support)
            $table->unsignedBigInteger('order_line_id')
                  ->nullable()
                  ->after('order_id')
                  ->comment('Links return to specific order line');

            // 3rd permanent deduction
            $table->decimal('refund_gateway_charges', 12, 2)
                  ->default(0.00)
                  ->after('refund_shipping')
                  ->comment('Payment gateway charges deducted from refund');

            // Dynamic fields (finance add/remove)
            $table->json('refund_extra_deductions')
                  ->nullable()
                  ->after('refund_gateway_charges')
                  ->comment('Dynamic deduction fields added/removed by finance');

            // Manual approval
            $table->unsignedBigInteger('refund_approved_by')
                  ->nullable()
                  ->after('total_refund_amount')
                  ->comment('Finance user who approved the final refund');

            $table->enum('refund_approval_status', ['pending', 'approved', 'rejected'])
                  ->default('pending')
                  ->after('refund_approved_by')
                  ->comment('Manual approval gate for final refund');

            // Indexes
            $table->index('order_line_id', 'returns_order_line_id_index');
            $table->index('refund_approval_status', 'returns_refund_approval_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('returns_order_line_id_index');
            $table->dropIndex('returns_refund_approval_status_index');
            $table->dropColumn([
                'order_line_id',
                'refund_gateway_charges',
                'refund_extra_deductions',
                'refund_approved_by',
                'refund_approval_status',
            ]);
        });
    }
};