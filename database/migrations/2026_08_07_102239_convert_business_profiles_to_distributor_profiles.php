<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Rename table from business_profiles to distributor_profiles
        Schema::rename('business_profiles', 'distributor_profiles');

        // Step 2: Add missing distributor KYC columns (encrypted fields)
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->text('encrypted_aadhaar')->nullable()->after('user_id');
            $table->text('encrypted_pan')->nullable()->after('encrypted_aadhaar');
            $table->text('encrypted_bank_account')->nullable()->after('encrypted_pan');
            $table->string('bank_ifsc')->nullable()->after('encrypted_bank_account');
            $table->string('bank_holder_name')->nullable()->after('bank_ifsc');
            $table->enum('kyc_status', ['pending', 'verified', 'rejected'])->default('pending')->after('bank_holder_name');
        });

        // Step 3: Make existing columns nullable (since they are not required for all distributors)
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->string('company_name')->nullable()->change();
            $table->string('gst_number')->nullable()->change();
            $table->text('billing_address')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->string('pin_code')->nullable()->change();
            $table->string('country')->nullable()->change();
            $table->string('document_path')->nullable()->change();
        });
        
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'gst_number', 'billing_address', 
                               'city', 'state', 'pin_code', 'country', 'document_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Drop the added columns first
        Schema::table('distributor_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'encrypted_aadhaar',
                'encrypted_pan',
                'encrypted_bank_account',
                'bank_ifsc',
                'bank_holder_name',
                'kyc_status'
            ]);
        });

        // Step 2: Rename back
        Schema::rename('distributor_profiles', 'business_profiles');
    }
};