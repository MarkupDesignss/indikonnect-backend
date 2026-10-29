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
        Schema::table('correction_requests', function (Blueprint $table) {
            $table->enum('type', [
                'name_dob_request',
                'phone_request',
            ])->default('phone_request');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ccorrection_requests', function (Blueprint $table) {
            //
        });
    }
};
