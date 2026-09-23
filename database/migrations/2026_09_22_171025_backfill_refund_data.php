<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill adjusted_amount for existing refunds
        DB::table('refunds')
            ->where('adjusted_amount', 0.00)
            ->where('amount', '>', 0.00)
            ->update(['adjusted_amount' => DB::raw('amount')]);

        // Mark existing returns as approved
        DB::table('returns')
            ->whereIn('status', ['approved', 'partially_approved', 'completed'])
            ->where('refund_approval_status', 'pending')
            ->update(['refund_approval_status' => 'approved']);
    }

    public function down(): void
    {
        // No rollback for data backfill
    }
};