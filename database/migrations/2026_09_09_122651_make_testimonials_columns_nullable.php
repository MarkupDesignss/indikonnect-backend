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
        Schema::table('testimonials', function (Blueprint $table) {
            $table->string('video_path')->nullable()->change();
            $table->string('video_title')->nullable()->change();
            $table->string('person_name')->nullable()->change();
            $table->string('heading')->nullable()->change();
            $table->decimal('rating', 3, 1)->nullable()->change();
            $table->text('text')->nullable()->change();
            $table->boolean('is_active')->nullable()->default(true)->change();
            $table->integer('display_order')->nullable()->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
