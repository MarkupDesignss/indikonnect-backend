<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correction_requests', function (Blueprint $table) {
            $table->string('new_email')->nullable()->after('email');
        });

        DB::statement("
            ALTER TABLE correction_requests
            MODIFY COLUMN type ENUM(
                'name_dob_request',
                'phone_request',
                'email_request'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('correction_requests', function (Blueprint $table) {
            $table->dropColumn('new_email');
        });

        DB::statement("
            ALTER TABLE correction_requests
            MODIFY COLUMN type ENUM(
                'name_dob_request',
                'phone_request'
            ) NOT NULL
        ");
    }
};
