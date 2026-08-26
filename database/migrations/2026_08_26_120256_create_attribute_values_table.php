<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_master_id')
                  ->constrained('attribute_masters')
                  ->onDelete('cascade');
            $table->string('value', 191);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->unique(['attribute_master_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};