<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // 1. HSN Code
            $table->string('hsn_code', 20)->nullable()->after('product_code');
            
            // 2. UOM
            $table->string('uom', 10)->default('NOS')->after('hsn_code');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hsn_code', 'uom']);
        });
    }
};