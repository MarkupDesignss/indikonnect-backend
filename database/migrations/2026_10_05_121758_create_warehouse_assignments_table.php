<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_warehouse_assignments', function (Blueprint $table) {
            $table->id();

            // ============================================
            // CORE RELATIONSHIP
            // ============================================

            $table->unsignedBigInteger('admin_id')
                ->comment('Admin/Sub-admin assigned');

            $table->unsignedBigInteger('warehouse_id')
                ->comment('Warehouse where assigned');

            $table->unsignedBigInteger('role_id')
                ->nullable()
                ->comment('Role assigned to admin for this warehouse');

            // ============================================
            // ASSIGNMENT DETAILS
            // ============================================

            $table->boolean('is_primary')
                ->default(false)
                ->comment('Is this primary warehouse for admin?');

            // ============================================
            // TIMELINE
            // ============================================

            $table->date('assigned_from')
                ->nullable()
                ->comment('Assignment start date');

            $table->date('assigned_until')
                ->nullable()
                ->comment('Assignment end date (null = permanent)');

            // ============================================
            // STATUS
            // ============================================

            $table->boolean('is_active')
                ->default(true)
                ->comment('Is this assignment currently active?');

            // ============================================
            // AUDIT TRAIL
            // ============================================

            $table->unsignedBigInteger('assigned_by')
                ->nullable()
                ->comment('Super admin who made this assignment');

            $table->text('notes')
                ->nullable()
                ->comment('Any additional notes about this assignment');

            // ============================================
            // TRACKING
            // ============================================

            $table->timestamps();

            // ============================================
            // FOREIGN KEYS
            // ============================================

            $table->foreign('admin_id')
                ->references('id')
                ->on('admins')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->foreign('assigned_by')
                ->references('id')
                ->on('admins')
                ->onDelete('set null')
                ->onUpdate('cascade');

            // If role_id references a roles table, uncomment:
            /*
            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->onDelete('set null')
                ->onUpdate('cascade');
            */

            // ============================================
            // UNIQUE CONSTRAINTS
            // ============================================

            $table->unique(
                ['admin_id', 'warehouse_id'],
                'unique_admin_warehouse'
            );

            // ============================================
            // INDEXES
            // ============================================
            $table->index('is_active', 'idx_assign_active');
            $table->index('is_primary', 'idx_assign_primary');

            $table->index(
                ['is_active', 'warehouse_id'],
                'idx_assign_active_wh'
            );

            $table->index(
                ['is_active', 'admin_id'],
                'idx_assign_active_admin'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_warehouse_assignments');
    }
};
