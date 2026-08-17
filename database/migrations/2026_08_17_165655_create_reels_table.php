<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            
            // Video Details
            $table->string('video_url');
            $table->string('title');
            
            // Creator Details
            $table->string('creator_handle');      // "@ananya.glow"
            $table->integer('followers_count')->default(0); // "412k"
            
            $table->foreignId('product_id')
                  ->constrained()  
                  ->onDelete('cascade'); 
            
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reels');
    }
};