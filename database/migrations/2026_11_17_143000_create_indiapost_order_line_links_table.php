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
        Schema::create('indiapost_order_line_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->index();
            $table->string('order_reference', 100)->index();
            $table->unsignedBigInteger('order_line_id')->index();
            $table->char('barcode_no', 13)->index();
            $table->datetime('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indiapost_order_line_links');
    }
};