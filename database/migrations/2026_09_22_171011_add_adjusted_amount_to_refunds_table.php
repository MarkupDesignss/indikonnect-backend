<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->decimal('adjusted_amount', 12, 2)
                  ->default(0.00)
                  ->after('amount')
                  ->comment('Final adjusted refund amount after all deductions');

            $table->json('deduction_breakdown')
                  ->nullable()
                  ->after('adjusted_amount')
                  ->comment('Audit snapshot of all deductions (cancel + return)');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['adjusted_amount', 'deduction_breakdown']);
        });
    }
};