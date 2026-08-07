<?php
// database/migrations/2026_08_07_xxxxxx_reorder_created_updated_in_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Move created_at to after activation_date
        DB::statement('ALTER TABLE users MODIFY created_at TIMESTAMP NULL AFTER activation_date');
        // Move updated_at to after created_at (so they appear together at the end)
        DB::statement('ALTER TABLE users MODIFY updated_at TIMESTAMP NULL AFTER created_at');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Move them back to their original position (after profile_picture, before role_id)
        DB::statement('ALTER TABLE users MODIFY created_at TIMESTAMP NULL AFTER profile_picture');
        DB::statement('ALTER TABLE users MODIFY updated_at TIMESTAMP NULL AFTER created_at');
    }
};