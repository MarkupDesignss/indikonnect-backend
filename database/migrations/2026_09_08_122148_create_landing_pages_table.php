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
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('section')->index(); // hero, chapter_one, chapter_two, etc.
            $table->string('section_title')->nullable();
            $table->string('section_subtitle')->nullable();
            $table->longText('description')->nullable();
            $table->json('images')->nullable(); // Multiple images as JSON array
            $table->string('color')->nullable(); // For brand colors
            $table->json('content_data')->nullable(); // For flexible content like products, testimonials, levels
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
