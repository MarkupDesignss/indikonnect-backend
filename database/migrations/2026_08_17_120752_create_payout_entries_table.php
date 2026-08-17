<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_run_id')->constrained()->onDelete('cascade');
            $table->foreignId('distributor_id')->constrained('users');
            $table->decimal('gross_commission', 15, 2);
            $table->decimal('tds', 15, 2);
            $table->decimal('net_payable', 15, 2);
            $table->enum('status', ['pending', 'released', 'held'])->default('pending');
            $table->string('held_reason')->nullable();
            $table->timestamps();

            $table->index('payout_run_id');
            $table->index('distributor_id');
            $table->index('status');
            $table->unique(['payout_run_id', 'distributor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_entries');
    }
};