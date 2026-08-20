<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify existing delivery_status enum
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN delivery_status ENUM(
                'pending',
                'shipped',
                'delivered',
                'cancelled'
            ) DEFAULT 'pending'
        ");

        // Modify existing return_status enum
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN return_status ENUM(
                'none',
                'pending',
                'approved',
                'rejected',
                'returned'
            ) DEFAULT 'none'
        ");

        Schema::table('order_lines', function (Blueprint $table) {

            // Delivery timestamps
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('return_at')->nullable();
            $table->timestamp('shipped_at')->nullable();

            // Return timestamps
            $table->timestamp('return_requested_at')->nullable();
            $table->timestamp('return_approved_at')->nullable();
            $table->timestamp('return_rejected_at')->nullable();
            $table->timestamp('return_completed_at')->nullable();

            // Return details
            $table->string('return_reason', 500)->nullable();
            $table->string('return_rejection_reason', 500)->nullable();

            $table->integer('return_quantity')->default(0);
            $table->boolean('is_returnable')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn([
                'delivered_at',
                'shipped_at',
                'return_requested_at',
                'return_approved_at',
                'return_rejected_at',
                'return_completed_at',
                'return_reason',
                'return_rejection_reason',
                'return_quantity',
                'is_returnable',
            ]);
        });

        // Restore delivery_status
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN delivery_status ENUM(
                'pending',
                'shipped',
                'delivered',
                'cancelled'
            ) DEFAULT 'pending'
        ");

        // Restore return_status
        DB::statement("
            ALTER TABLE order_lines
            MODIFY COLUMN return_status ENUM(
                'none',
                'pending',
                'approved',
                'rejected',
                'returned'
            ) DEFAULT 'none'
        ");
    }
};