<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('recipient_name');
            $table->string('contact_number');
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('postcode');
            $table->string('country')->default('India');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_billing')->default(true);
            $table->boolean('is_delivery')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('addresses');
    }
};