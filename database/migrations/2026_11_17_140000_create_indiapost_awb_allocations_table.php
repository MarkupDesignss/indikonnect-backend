<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('indiapost_awb_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('order_line_id')->nullable();
            $table->string('order_reference', 100)->nullable();
            $table->char('barcode_no', 13)->index();
            $table->char('serial_no', 8)->index();
            $table->string('series_key', 80)->index();
            $table->datetime('created_at', 3)->useCurrent();
            $table->unsignedBigInteger('scope_line_id')
                ->nullable()
                ->storedAs('IFNULL(order_line_id, 0)')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indiapost_awb_allocations');
    }
};