<?php
// database/migrations/2026_08_12_000003_create_consent_records_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('consent_type');
            $table->boolean('is_agreed')->default(false);
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('agreed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};