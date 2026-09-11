<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropForeignKeyIfExists('refunds', 'order_line_id');

        DB::statement('
            ALTER TABLE `refunds` 
            MODIFY `order_line_id` BIGINT(20) UNSIGNED NULL AFTER `order_id`
        ');

        Schema::table('refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('refunds', 'notes')) {
                $table->text('notes')->nullable()->after('failure_reason');
            }
            if (!Schema::hasColumn('refunds', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('refunds', 'refund_method')) {
                $table->string('refund_method', 50)->nullable()->after('approved_by');
            }
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->foreign('order_line_id')
                ->references('id')
                ->on('order_lines')
                ->nullOnDelete();
        });

        Schema::table('refunds', function (Blueprint $table) {
            if (Schema::hasTable('admins')) {
                $table->foreign('approved_by')
                    ->references('id')
                    ->on('admins')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        
        $this->dropForeignKeyIfExists('refunds', 'approved_by');

        Schema::table('refunds', function (Blueprint $table) {
            if (Schema::hasColumn('refunds', 'refund_method')) {
                $table->dropColumn('refund_method');
            }
            if (Schema::hasColumn('refunds', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
            if (Schema::hasColumn('refunds', 'notes')) {
                $table->dropColumn('notes');
            }
        });

        
        $this->dropForeignKeyIfExists('refunds', 'order_line_id');

        DB::statement('
            ALTER TABLE `refunds` 
            MODIFY `order_line_id` BIGINT(20) UNSIGNED NULL AFTER `updated_at`
        ');

        Schema::table('refunds', function (Blueprint $table) {
            $table->foreign('order_line_id')
                ->references('id')
                ->on('order_lines')
                ->nullOnDelete();
        });
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $database = DB::getDatabaseName();

        $fk = DB::selectOne("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = ? 
              AND TABLE_NAME = ? 
              AND COLUMN_NAME = ? 
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ", [$database, $table, $column]);

        if ($fk) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
        }
    }
};