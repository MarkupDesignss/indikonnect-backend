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
        // Denormalized reserved counters
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('reserved_inventory')
                ->default(0)
                ->after('stock_quantity');
        });

        // Reservation ledger
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_token', 64)->index();

            // No foreign keys
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id');

            $table->string('item_reference_id')->nullable();
            $table->unsignedInteger('quantity');

            $table->enum('status', ['held', 'consumed', 'released'])
                ->default('held');

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status'], 'inv_res_sku_status_idx');
            $table->index(['order_id', 'status'], 'inv_res_order_status_idx');
        });

        // Order-level reservation metadata
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reservation_token', 64)
                ->nullable()
                ->after('order_reference')
                ->index();

            $table->timestamp('reservation_expires_at')
                ->nullable()
                ->after('reservation_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reservation_token', 'reservation_expires_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reserved_inventory');
        });
    }
};
