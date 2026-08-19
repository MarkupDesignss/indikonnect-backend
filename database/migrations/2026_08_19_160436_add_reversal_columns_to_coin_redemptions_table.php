<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_redemptions', function (Blueprint $table) {
            $table->string('reversal_reason')->nullable()->after('status');
            $table->timestamp('reversed_at')->nullable()->after('authorized_at');
        });
    }

    public function down(): void
    {
        Schema::table('coin_redemptions', function (Blueprint $table) {
            $table->dropColumn(['reversal_reason', 'reversed_at']);
        });
    }
};