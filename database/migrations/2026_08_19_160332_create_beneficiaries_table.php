<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('full_name');
            $table->string('relationship'); // e.g., spouse, child, parent
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->decimal('share_percentage', 5, 2)->default(100.00); // if multiple, sum must be 100
            $table->boolean('is_primary')->default(false);
            $table->string('address')->nullable();
            $table->timestamp('confirmed_at')->nullable(); // when OTP confirmed
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};