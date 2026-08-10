<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FR-ST-006: Product reviews and ratings submission
     * FR-ST-007: Display of approved reviews
     * FR-AD-012: Admin moderation queue
     */
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();

            // Reviewer
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Product being reviewed
            $table->foreignId('product_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Order to verify purchase (FR-ST-006: only verified purchasers)
            $table->foreignId('order_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();

            // Review content
            $table->tinyInteger('rating')->unsigned(); // 1 to 5
            $table->text('review_text')->nullable();

            // Moderation status (FR-ST-006: pending by default)
            $table->enum('status', ['pending', 'approved', 'rejected'])
                  ->default('pending');

            // Admin moderation details (FR-AD-012)
            $table->foreignId('moderated_by')
                  ->nullable()
                  ->constrained('admins')
                  ->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes for performance
            $table->index(['product_id', 'status']);    // For fetching approved reviews
            $table->index(['user_id', 'product_id']);   // For checking existing reviews
            $table->index('order_id');                  // For purchase verification

            // One review per user per product (FR-ST-006)
            $table->unique(['user_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};