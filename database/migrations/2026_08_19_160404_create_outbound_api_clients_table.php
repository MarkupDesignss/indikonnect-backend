<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('api_key')->unique();
            $table->text('secret'); // encrypted, never shown
            $table->json('scopes')->nullable(); // e.g., ["products", "orders"]
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // optional expiry
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('api_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_api_clients');
    }
};