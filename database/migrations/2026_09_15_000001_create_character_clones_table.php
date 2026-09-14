<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_clones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('jump_clone_id');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('location_id');
            $table->string('location_type', 20);
            $table->json('implants'); // list of implant type ids
            $table->unique(['character_id', 'jump_clone_id']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('last_clone_jump_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('last_clone_jump_date');
        });
        Schema::dropIfExists('character_clones');
    }
};
