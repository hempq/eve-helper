<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('skill_id')->index();
            $table->unsignedBigInteger('required_skill_id');
            $table->unsignedTinyInteger('required_level');
            $table->unique(['skill_id', 'required_skill_id']);
        });

        Schema::create('implant_bonuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('type_id')->index();
            $table->string('attribute', 20);
            $table->unsignedTinyInteger('bonus');
            $table->unique(['type_id', 'attribute']);
        });

        Schema::create('character_implants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('type_id');
            $table->unique(['character_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_implants');
        Schema::dropIfExists('implant_bonuses');
        Schema::dropIfExists('skill_prerequisites');
    }
};
