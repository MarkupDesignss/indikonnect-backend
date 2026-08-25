<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('distributor_profiles', function (Blueprint $table) {
            // Application lifecycle status
            $table->enum('application_status', [
                'draft',
                'submitted',
                'under_review',
                'returned',
                'approved',
                'rejected'
            ])->default('draft')->after('kyc_status');

            // Admin review tracking
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            
            // References admins table, not users
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $table->foreign('reviewed_by')->references('id')->on('admins')->onDelete('set null');

            // Rejection/return reason
            $table->text('rejection_reason')->nullable()->after('reviewed_by');
        });
    }

    public function down()
    {
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'application_status',
                'reviewed_at',
                'reviewed_by',
                'rejection_reason'
            ]);
        });
    }
};