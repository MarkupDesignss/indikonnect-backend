<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_steps', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable()->comment('Main title - only one record will have this');
            $table->string('number', 10)->comment('Step number e.g., 01, 02');
            $table->string('subtitle')->comment('Step subtitle e.g., Associate, Consultant');
            $table->text('description')->comment('Detailed description of the step');
            $table->integer('order')->default(0)->comment('Order for sorting');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('order');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_steps');
    }
};
