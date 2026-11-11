<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('green_coin', 10, 4)
                ->default(0)
                ->after('commission_value')
                ->comment('Green Coins per unit — paid to uplines on settlement');

            $table->decimal('blue_coin', 10, 4)
                ->default(0)
                ->after('green_coin')
                ->comment('Blue Coins per unit — paid to buyer + uplines on settlement');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['green_coin', 'blue_coin']);
        });
    }
};