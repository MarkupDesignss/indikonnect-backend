<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')
                  ->constrained('users')
                  ->onDelete('cascade')
                  ->index();
            $table->string('period', 7)->index(); // YYYY-MM
            $table->enum('entry_type', [
                'commission', 'bonus', 'coin_award',
                'coin_redeemed', 'reversal', 'settlement'
            ]);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('tds_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('order_reference')->nullable()->index();
            $table->enum('status', ['pending', 'released', 'reversed'])->default('pending');
            $table->timestamps();

            // Composite index for faster filters
            $table->index(['distributor_id', 'period', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger');
    }
};