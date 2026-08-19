<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->enum('type', ['return', 'cooling_off', 'buyback'])
                  ->default('return')
                  ->after('user_id')
                  ->comment('Distinguishes regular return, cooling-off withdrawal, or buy-back request');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};