<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1. अगर Foreign Key है तो drop करें
            // (नाम जानने के लिए नीचे देखें)
            $table->dropForeign(['sponsor_id']);    // array में column name

            // 2. साधारण Index drop करें (अगर है)
            // यह auto-generated नाम हो सकता है "users_sponsor_id_index"
            $table->dropIndex(['sponsor_id']);       // या dropIndex('users_sponsor_id_index')

            // 3. अब column का type safely बदलें (length 191 दें)
            $table->string('sponsor_id', 191)->nullable()->change();

            // 4. (Optional) अगर index वापस चाहिए तो add करें
            // $table->index('sponsor_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // वापस integer में बदलें (मान लें कि पहले integer था)
            $table->integer('sponsor_id')->nullable()->change();

            // फिर से index / foreign key वापस add करें
            // $table->foreign('sponsor_id')->references('id')->on('sponsors');
            // $table->index('sponsor_id');
        });
    }
};