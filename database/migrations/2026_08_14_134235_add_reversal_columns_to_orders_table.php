<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function () {
            // Move created_at to the end (after coupon_code)
            DB::statement('ALTER TABLE `orders` MODIFY COLUMN `created_at` timestamp NULL AFTER `coupon_code`');
            // Move updated_at to the end (after created_at)
            DB::statement('ALTER TABLE `orders` MODIFY COLUMN `updated_at` timestamp NULL AFTER `created_at`');
            // Move deleted_at to the end (after updated_at)
            DB::statement('ALTER TABLE `orders` MODIFY COLUMN `deleted_at` timestamp NULL AFTER `updated_at`');
        });
    }

    public function down(): void
    {
        // Revert back (optional – puts them back after tax_breakdown)
        Schema::table('orders', function () {
            DB::statement('ALTER TABLE `orders` MODIFY COLUMN `created_at` timestamp NULL AFTER `tax_breakdown`');
            DB::statement('ALTER TABLE `orders` MODIFY COLUMN `updated_at` timestamp NULL AFTER `created_at`');
            DB::statement('ALTER TABLE `orders` MODIFY COLUMN `deleted_at` timestamp NULL AFTER `updated_at`');
        });
    }
};