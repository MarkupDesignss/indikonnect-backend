<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();

            // FR-CO-011: Links to the order being returned
            $table->foreignId('order_id')->constrained()->onDelete('cascade');

            // FR-CO-011: Who requested the return
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // FR-CO-011: Which products and quantities are being returned
            // Structure: [{"product_id": 1, "quantity": 2, "reason": "defective"}]
            $table->json('items');

            // FR-CO-011 / FR-AD-008: Status of the return request
            $table->enum('status', [
                'pending',
                'approved',
                'partially_approved',
                'rejected',
                'received',    // goods received back in warehouse
                'completed'    // refund and reversal done
            ])->default('pending');

            // FR-CO-011: Overall reason for return
            $table->string('reason')->nullable();

            // FR-AD-008: Admin notes (visible to requester on rejection/part-approval)
            $table->text('admin_notes')->nullable();

            // FR-CO-011 / FR-AD-008: Timestamps for key milestones
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // FR-AD-008: Who processed the return (admin)
            $table->foreignId('admin_id')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};