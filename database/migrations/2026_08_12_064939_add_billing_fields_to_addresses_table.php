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
        Schema::table('addresses', function (Blueprint $table) {
            // Add billing-specific fields
            $table->string('billing_recipient_name')->nullable()->after('recipient_name');
            $table->string('billing_contact_number')->nullable()->after('contact_number');
            $table->string('billing_address_line_1')->nullable()->after('address_line_1');
            $table->string('billing_address_line_2')->nullable()->after('address_line_2');
            $table->string('billing_city')->nullable()->after('city');
            $table->string('billing_state')->nullable()->after('state');
            $table->string('billing_postcode')->nullable()->after('postcode');
            $table->string('billing_country')->nullable()->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            //
        });
    }
};
