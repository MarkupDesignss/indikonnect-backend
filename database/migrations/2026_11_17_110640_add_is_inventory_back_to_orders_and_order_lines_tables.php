<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_inventory_back')
                ->default(false)
                ->after('amount_paid');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->boolean('is_inventory_back')
                ->default(false)
                ->after('return_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_inventory_back');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('is_inventory_back');
        });
    }
};
