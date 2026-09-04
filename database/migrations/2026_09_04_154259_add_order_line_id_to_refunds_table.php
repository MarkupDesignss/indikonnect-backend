<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->after('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropForeign(['order_line_id']);
            $table->dropColumn('order_line_id');
        });
    }
};
