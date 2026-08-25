<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('returns', function (Blueprint $table) {
            if (!Schema::hasColumn('returns', 'extra_data')) {
                $table->json('extra_data')->nullable()->after('items');
            }
        });
    }

    public function down()
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('extra_data');
        });
    }
};