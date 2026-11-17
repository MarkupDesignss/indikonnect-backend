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
        Schema::create('indiapost_awb_counters', function (Blueprint $table) {
            $table->string('series_key', 80)->primary();
            $table->unsignedBigInteger('next_serial');
            $table->datetime('created_at', 3)->useCurrent();
            $table->datetime('updated_at', 3)
                ->useCurrent()
                ->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indiapost_awb_counters');
    }
};