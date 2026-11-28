<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->string('preferred_placement_ba_id', 191)
                ->nullable()
                ->after('sponsor_id');

            $table->string('placement_tracking_centre', 10)
                ->nullable()
                ->after('preferred_placement_ba_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropColumn([
                'preferred_placement_ba_id',
                'placement_tracking_centre',
            ]);
        });
    }
};