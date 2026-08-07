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
        Schema::table('users', function (Blueprint $table) {
            // 1️⃣ Remove business_status (now using distributor_profiles.kyc_status)
            $table->dropColumn('business_status');

            // 2️⃣ Change role_id from varchar to foreign key
            // First, ensure the column is nullable and drop it to recreate properly
            // If you already have data, you need to handle it carefully.
            // Option A: If no data yet, just drop and recreate
            $table->dropColumn('role_id');
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            
            // 3️⃣ Add distributor-specific fields
            $table->string('distributor_id')->nullable()->unique()->after('id');
            $table->foreignId('sponsor_id')->nullable()->constrained('users')->nullOnDelete()->after('distributor_id');
            $table->enum('placement_leg', ['left', 'right'])->nullable()->after('sponsor_id');
            $table->enum('distributor_status', ['active', 'suspended', 'withdrawn', 'closed'])->default('active')->after('placement_leg');
            $table->timestamp('activation_date')->nullable()->after('distributor_status');

            // 4️⃣ Change account_type to enum for better validation
            // First drop the old column and recreate as enum
            // But careful: if you have data, you need to preserve it
            // Since this is a new project, we can drop and recreate
            $table->dropColumn('account_type');
            $table->enum('account_type', ['customer', 'distributor', 'admin'])->default('customer')->after('country');
        });

        // 5️⃣ Add indexes for performance
        Schema::table('users', function (Blueprint $table) {
            $table->index(['distributor_id', 'sponsor_id']);
            $table->index('distributor_status');
            $table->index('activation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Restore business_status
            $table->string('business_status')->nullable()->index()->after('is_active');

            // Restore role_id as varchar
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
            $table->string('role_id')->nullable()->after('profile_picture');

            // Drop distributor fields
            $table->dropForeign(['sponsor_id']);
            $table->dropColumn([
                'distributor_id',
                'sponsor_id',
                'placement_leg',
                'distributor_status',
                'activation_date'
            ]);

            // Restore account_type as varchar
            $table->dropColumn('account_type');
            $table->string('account_type')->index()->after('country');
        });
    }
};