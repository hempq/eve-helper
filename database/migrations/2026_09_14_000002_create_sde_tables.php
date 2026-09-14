<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_types', function (Blueprint $table) {
            $table->unsignedBigInteger('type_id')->primary();
            $table->unsignedBigInteger('group_id')->index();
            $table->string('name')->index();
            $table->double('volume')->nullable();
            $table->unsignedBigInteger('market_group_id')->nullable();
            $table->boolean('published')->default(false);
        });

        Schema::create('skill_types', function (Blueprint $table) {
            $table->unsignedBigInteger('type_id')->primary();
            $table->unsignedTinyInteger('rank');
            $table->string('primary_attribute', 20);
            $table->string('secondary_attribute', 20);
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->unsignedBigInteger('region_id')->primary();
            $table->string('name');
        });

        Schema::create('constellations', function (Blueprint $table) {
            $table->unsignedBigInteger('constellation_id')->primary();
            $table->unsignedBigInteger('region_id')->index();
            $table->string('name');
        });

        Schema::create('solar_systems', function (Blueprint $table) {
            $table->unsignedBigInteger('system_id')->primary();
            $table->unsignedBigInteger('constellation_id')->index();
            $table->unsignedBigInteger('region_id')->index();
            $table->string('name')->index();
            $table->double('security');
        });

        Schema::create('system_jumps', function (Blueprint $table) {
            $table->unsignedBigInteger('from_system_id');
            $table->unsignedBigInteger('to_system_id');
            $table->primary(['from_system_id', 'to_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_jumps');
        Schema::dropIfExists('solar_systems');
        Schema::dropIfExists('constellations');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('skill_types');
        Schema::dropIfExists('item_types');
    }
};
