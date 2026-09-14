<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->primary();
            $table->unsignedBigInteger('system_id')->index();
            $table->string('name');
        });

        Schema::create('character_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('type_id')->index();
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('location_id')->index();
            $table->string('location_flag', 50);
            $table->string('location_type', 20);
            $table->boolean('is_singleton');
            $table->unique(['character_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_assets');
        Schema::dropIfExists('stations');
    }
};
