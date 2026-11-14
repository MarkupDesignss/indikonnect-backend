<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'partner_product_ref')) {
                $table->string('partner_product_ref', 100)
                      ->nullable()
                      ->after('ignite_product_id')
                      ->comment('Ignite partner_product_ref — primary mapping key');

                $table->index('partner_product_ref', 'idx_products_partner_product_ref');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'partner_product_ref')) {
                $table->dropIndex('idx_products_partner_product_ref');
                $table->dropColumn('partner_product_ref');
            }
        });
    }
};