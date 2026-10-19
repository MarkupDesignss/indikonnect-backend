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
        Schema::table('coin_redemptions', function (Blueprint $table) {
            if (!Schema::hasColumn('coin_redemptions', 'point_type')) {
                $table->string('point_type', 20)->nullable()->after('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coin_redemptions', function (Blueprint $table) {
            $table->dropColumn('point_type');
        });
    }
};
