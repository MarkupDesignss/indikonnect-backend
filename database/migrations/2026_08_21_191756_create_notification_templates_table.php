<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();

            // Event type (e.g., order_confirmed, payout_released, kyc_approved)
            $table->string('event_type', 100)->index();

            // Channel: email, sms, push, database
            $table->string('channel', 50)->index();

            // Subject (for email/push) - nullable for SMS
            $table->string('subject', 255)->nullable();

            // Message body (supports placeholders like {{user_name}})
            $table->text('body');

            // List of placeholders used in body (for admin reference)
            $table->json('placeholders')->nullable();

            // Is this template active? If false, system uses fallback/hardcoded.
            $table->boolean('is_active')->default(true);

            // Version number - increments on every edit
            $table->unsignedInteger('version')->default(1);

            // Audit trail: who last updated this template
            $table->foreignId('updated_by')->nullable()->constrained('admins')->onDelete('set null');

            $table->timestamps();

            // Ensure unique combination of event + channel (only one active per pair)
            // We'll handle active uniqueness in application logic, but we index them together.
            $table->unique(['event_type', 'channel', 'version']); 
            // Note: version increments, so same (event, channel) can have multiple versions.
            // The "is_active" flag will determine which one is currently used.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};