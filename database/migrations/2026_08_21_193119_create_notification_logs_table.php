<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            // Who received this notification
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            // Which event (copied from template)
            $table->string('event_type', 100)->index();

            // Which channel (email, sms, push, database)
            $table->string('channel', 50);

            // Which template version was used at the time of sending
            $table->unsignedInteger('template_version')->nullable();

            // Status: pending, sent, delivered, failed, retrying
            $table->string('status', 30)->default('pending')->index();

            // Raw response from gateway (SMS/Email provider)
            $table->json('gateway_response')->nullable();

            // Retry count (will be compared against setting('notification_retry_attempts', 3))
            $table->tinyInteger('retry_count')->unsigned()->default(0);

            // Timestamp when actually sent (or last attempt)
            $table->timestamp('sent_at')->nullable();

            // Additional metadata (e.g., order_id, payout_run_id, etc.)
            $table->json('meta')->nullable();

            // Who manually retried this (admin user)
            $table->foreignId('retried_by')->nullable()->constrained('admins')->onDelete('set null');

            // Self-reference: original log ID if this is a retry
            $table->foreignId('original_log_id')->nullable()->constrained('notification_logs')->onDelete('set null');

            $table->timestamps();

            // Composite indexes for filtering
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['event_type', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};