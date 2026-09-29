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
        // order_returns
        Schema::table('returns', function (Blueprint $table) {
            $table->string('resolution')
                ->nullable()
                ->after('status');

            $table->unsignedBigInteger('replacement_order_id')
                ->nullable()
                ->after('resolution');

            $table->foreign('replacement_order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });

        // orders
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_order_id')
                ->nullable()
                ->after('id');

            $table->boolean('is_replacement')
                ->default(false)
                ->after('parent_order_id');

            $table->foreign('parent_order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });

        // order_lines
        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_line_id')
                ->nullable()
                ->after('order_id');

            $table->boolean('is_replacement')
                ->default(false)
                ->after('parent_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_returns_and_order_lines_tables', function (Blueprint $table) {
            //
        });
    }
};
