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
        DB::statement('ALTER TABLE `returns` MODIFY `order_id` BIGINT UNSIGNED NULL');

        DB::statement("
            ALTER TABLE distributor_profiles
            MODIFY application_status ENUM(
                'draft','submitted','under_review','returned',
                'approved','rejected','withdrawn'
            ) NOT NULL DEFAULT 'draft'
        ");

        Schema::table('distributor_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('distributor_profiles', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->after('reviewed_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE `returns` MODIFY `order_id` BIGINT UNSIGNED NOT NULL');
        DB::statement("
            ALTER TABLE distributor_profiles
            MODIFY application_status ENUM(
                'draft','submitted','under_review','returned','approved','rejected'
            ) NOT NULL DEFAULT 'draft'
        ");
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
        });
    }
};
