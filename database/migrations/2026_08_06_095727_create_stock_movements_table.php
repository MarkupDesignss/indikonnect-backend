<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity'); // positive = receipt, negative = deduction
            $table->integer('available_quantity_after')->nullable();
            $table->string('reason')->nullable();
            // $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->onDelete('set null');

            $table->timestamps();

            $table->index('product_id');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_movements');
    }
};
