<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->string('pincode', 10)->nullable()->after('longitude');
            $table->string('city', 255)->nullable()->after('pincode');
            $table->string('state', 255)->nullable()->after('city');
        });
    }

    public function down()
    {
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->dropColumn(['pincode', 'city', 'state']);
        });
    }
};
