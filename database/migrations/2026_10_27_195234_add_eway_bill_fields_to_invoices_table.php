<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('eway_bill_no')->nullable()->after('pdf_path');
            $table->string('eway_bill_date')->nullable()->after('eway_bill_no');
            $table->string('eway_valid_upto')->nullable()->after('eway_bill_date');
            $table->string('eway_bill_status')->nullable()->after('eway_valid_upto');
            $table->string('transporter_id')->nullable()->after('eway_bill_status');

            $table->string('seller_city')->nullable()->after('seller_address');
            $table->string('seller_pincode', 10)->nullable()->after('seller_city');
            $table->string('seller_state_code', 2)->nullable()->after('seller_pincode');

            $table->string('buyer_city')->nullable()->after('buyer_address');
            $table->string('buyer_pincode', 10)->nullable()->after('buyer_city');
            $table->string('buyer_state_code', 2)->nullable()->after('buyer_pincode');
        });
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'eway_bill_no', 'eway_bill_date', 'eway_valid_upto',
                'eway_bill_status', 'transporter_id',
                'seller_city', 'seller_pincode', 'seller_state_code',
                'buyer_city', 'buyer_pincode', 'buyer_state_code',
            ]);
        });
    }
};