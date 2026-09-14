<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `order_lines`
                MODIFY COLUMN `created_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_returnable`,
                MODIFY COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `order_lines`
                MODIFY COLUMN `created_at` TIMESTAMP NULL DEFAULT NULL AFTER `commissionable_volume`,
                MODIFY COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`
        ");
    }
};