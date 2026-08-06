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
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id')->default(1)->after('id');
            $table->string('type')->after('admin_id');
            $table->string('title')->after('type');
            $table->text('message')->after('title');
            $table->string('reference_type')->nullable()->after('message');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium')->after('reference_id');
            $table->json('extra_data')->nullable()->after('priority');

            // Optional: if admins table exists
            // $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            //
        });
    }
};
