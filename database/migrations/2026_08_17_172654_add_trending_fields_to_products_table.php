<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_trending')
                ->default(false)
                ->after('is_published');

            $table->unsignedInteger('trending_sort_order')
                ->default(0)
                ->after('is_trending');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_trending',
                'trending_sort_order',
            ]);
        });
    }
};