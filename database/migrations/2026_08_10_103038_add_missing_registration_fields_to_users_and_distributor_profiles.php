<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add fields to users table
        Schema::table('users', function (Blueprint $table) {

            // Registration progress for multi-step
            $table->integer('registration_step')->default(0)->after('activation_date');
            $table->timestamp('registration_completed_at')->nullable()->after('registration_step');

            // Verification OTP fields
            $table->string('email_otp')->nullable()->after('email_verified_at');
            $table->timestamp('email_otp_expires_at')->nullable()->after('email_otp');
            $table->boolean('phone_verified')->default(0)->after('email_otp_expires_at');

            // Last 4 digits for display
            $table->string('aadhaar_last4')->nullable()->after('phone_verified');
            $table->string('pan_last4')->nullable()->after('aadhaar_last4');
            $table->string('account_last4')->nullable()->after('pan_last4');

            // Acceptances
            $table->boolean('accept_terms')->default(0)->after('account_last4');
            $table->boolean('accept_agreement')->default(0)->after('accept_terms');
            $table->boolean('accept_code_of_conduct')->default(0)->after('accept_agreement');
            $table->boolean('location_consent_given')->default(0)->after('accept_code_of_conduct');
        });

        // Rename table from business_profiles to distributor_profiles if needed
        // If table name is different, adjust accordingly
        Schema::table('distributor_profiles', function (Blueprint $table) {
            // Add new fields to distributor_profiles
            $table->string('branch_name')->nullable()->after('bank_ifsc');
            $table->enum('account_type', ['current', 'savings'])->nullable()->after('branch_name');
            $table->boolean('bank_verified')->default(0)->after('account_type');

            // Aadhaar fields
            $table->boolean('aadhaar_verified')->default(0)->after('encrypted_aadhaar');
            $table->timestamp('aadhaar_verified_at')->nullable()->after('aadhaar_verified');
            $table->boolean('aadhaar_consent')->default(0)->after('aadhaar_verified_at');

            // PAN fields
            $table->boolean('pan_verified')->default(0)->after('encrypted_pan');
            $table->timestamp('pan_verified_at')->nullable()->after('pan_verified');

            // Location fields
            $table->boolean('location_consent')->default(0)->after('kyc_status');
            $table->timestamp('location_consent_at')->nullable()->after('location_consent');
            $table->decimal('latitude', 10, 8)->nullable()->after('location_consent_at');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');

            // Registration completion
            $table->boolean('registration_completed')->default(0)->after('longitude');
            $table->timestamp('submitted_at')->nullable()->after('registration_completed');
            $table->timestamp('terms_accepted_at')->nullable()->after('submitted_at');

            // Bank name (if not already present)
            if (!Schema::hasColumn('distributor_profiles', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('bank_ifsc');
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'sponsor_id',
                'placement_leg',
                'activation_date',
                'registration_step',
                'registration_completed_at',
                'email_otp',
                'email_otp_expires_at',
                'phone_verified',
                'aadhaar_last4',
                'pan_last4',
                'account_last4',
                'accept_terms',
                'accept_agreement',
                'accept_code_of_conduct',
                'location_consent_given'
            ]);
        });

        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'branch_name',
                'account_type',
                'bank_verified',
                'aadhaar_verified',
                'aadhaar_verified_at',
                'aadhaar_consent',
                'pan_verified',
                'pan_verified_at',
                'location_consent',
                'location_consent_at',
                'latitude',
                'longitude',
                'registration_completed',
                'submitted_at',
                'terms_accepted_at'
            ]);

            if (Schema::hasColumn('distributor_profiles', 'bank_name')) {
                $table->dropColumn('bank_name');
            }
        });
    }
};
