<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->default('general')->index();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->enum('data_type', ['string', 'integer', 'boolean', 'json', 'email'])
                  ->default('string');
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};