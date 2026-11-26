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
            $table->json('ignite_cv_allocations')->nullable();
            $table->json('ignite_extra_tcs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'ignite_cv_allocations',
                'ignite_extra_tcs',
            ]);
        });
    }
};
