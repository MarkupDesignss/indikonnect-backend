<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7); // YYYY-MM
            $table->enum('status', ['pending', 'released', 'cancelled'])->default('pending');
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_tds', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('status');
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_runs');
    }
};