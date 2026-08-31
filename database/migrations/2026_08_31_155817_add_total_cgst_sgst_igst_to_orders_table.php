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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_cgst', 15, 2)
                ->default(0)
                ->after('total_gst');

            $table->decimal('total_sgst', 15, 2)
                ->default(0)
                ->after('total_cgst');

            $table->decimal('total_igst', 15, 2)
                ->default(0)
                ->after('total_sgst');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
};
