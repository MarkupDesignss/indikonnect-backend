<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('flat_rate', 10, 2)->default(50);
            $table->decimal('free_threshold', 10, 2)->default(500);
            $table->timestamps();
        });

        // Only one record will ever exist
        DB::table('shipping_settings')->insert([
            'flat_rate' => 50,
            'free_threshold' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_settings');
    }
};