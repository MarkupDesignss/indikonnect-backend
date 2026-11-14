<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('order_lines', 'ignite_catalog_item_id')) {
                $table->unsignedBigInteger('ignite_catalog_item_id')
                      ->nullable()
                      ->after('product_id')
                      ->comment('Ignite catalog_item_id resolved at order time');

                $table->index('ignite_catalog_item_id', 'idx_order_lines_ignite_catalog_item_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('order_lines', 'ignite_catalog_item_id')) {
                $table->dropIndex('idx_order_lines_ignite_catalog_item_id');
                $table->dropColumn('ignite_catalog_item_id');
            }
        });
    }
};