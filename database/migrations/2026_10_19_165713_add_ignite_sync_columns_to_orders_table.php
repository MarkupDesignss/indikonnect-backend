<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'ignite_sync_status')) {
                $table->string('ignite_sync_status')->nullable()->after('ignite_order_no');
            }
            if (!Schema::hasColumn('orders', 'ignite_sync_error')) {
                $table->text('ignite_sync_error')->nullable()->after('ignite_sync_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ignite_sync_status', 'ignite_sync_error']);
        });
    }
};