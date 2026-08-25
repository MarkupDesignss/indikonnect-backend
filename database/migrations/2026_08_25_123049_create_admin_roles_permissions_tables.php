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
        /*
        |--------------------------------------------------------------------------
        | Admin Roles
        |--------------------------------------------------------------------------
        */
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Admin Permissions
        |--------------------------------------------------------------------------
        */
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('module', 100)->nullable();
            $table->string('action', 100)->nullable();
            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Admin Role Permissions
        |--------------------------------------------------------------------------
        */
        Schema::create('admin_role_permissions', function (Blueprint $table) {
            $table->id();

            // No foreign key
            $table->unsignedBigInteger('admin_role_id');

            // No foreign key
            $table->unsignedBigInteger('admin_permission_id');

            $table->timestamps();

            // Prevent duplicate permission assignment to the same role
            $table->unique(
                ['admin_role_id', 'admin_permission_id'],
                'admin_role_permission_unique'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Admin Admin Role
        |--------------------------------------------------------------------------
        */
        Schema::create('admin_admin_role', function (Blueprint $table) {
            $table->id();

            // No foreign key
            $table->unsignedBigInteger('admin_id');

            // No foreign key
            $table->unsignedBigInteger('admin_role_id');

            $table->timestamps();

            // Prevent assigning the same role twice to an admin
            $table->unique(
                ['admin_id', 'admin_role_id'],
                'admin_admin_role_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_admin_role');
        Schema::dropIfExists('admin_role_permissions');
        Schema::dropIfExists('admin_permissions');
        Schema::dropIfExists('admin_roles');
    }
};