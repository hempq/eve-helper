<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->string('name');
            $table->timestamps();
        });

        // Only the user's chosen goals are stored; the full ordered plan
        // (with prerequisites) is derived from these on render.
        Schema::create('skill_plan_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('skill_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('target_level');
            $table->unique(['plan_id', 'skill_id']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->decimal('wallet_balance', 20, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
        Schema::dropIfExists('skill_plan_targets');
        Schema::dropIfExists('skill_plans');
    }
};
