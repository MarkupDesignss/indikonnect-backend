<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reorder created_at, updated_at, deleted_at to the end
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `created_at` timestamp NULL AFTER `coupon_code`');
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `updated_at` timestamp NULL AFTER `created_at`');
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `deleted_at` timestamp NULL AFTER `updated_at`');
    }

    public function down(): void
    {
        // Revert (optional)
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `created_at` timestamp NULL AFTER `tax_breakdown`');
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `updated_at` timestamp NULL AFTER `created_at`');
        DB::statement('ALTER TABLE `orders` MODIFY COLUMN `deleted_at` timestamp NULL AFTER `updated_at`');
    }
};