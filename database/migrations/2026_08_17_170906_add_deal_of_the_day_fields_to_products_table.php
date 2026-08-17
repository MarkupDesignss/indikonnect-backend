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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_deal_of_the_day')
                ->default(false)
                ->after('is_published');

            $table->timestamp('deal_of_the_day_starts_at')
                ->nullable()
                ->after('is_deal_of_the_day');

            $table->timestamp('deal_of_the_day_ends_at')
                ->nullable()
                ->after('deal_of_the_day_starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            //
        });
    }
};