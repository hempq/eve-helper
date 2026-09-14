<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('total_sp')->nullable();
            $table->unsignedInteger('unallocated_sp')->nullable();
            $table->unsignedTinyInteger('charisma')->nullable();
            $table->unsignedTinyInteger('intelligence')->nullable();
            $table->unsignedTinyInteger('memory')->nullable();
            $table->unsignedTinyInteger('perception')->nullable();
            $table->unsignedTinyInteger('willpower')->nullable();
            $table->unsignedTinyInteger('bonus_remaps')->nullable();
            $table->timestamp('last_remap_date')->nullable();
            $table->timestamp('accrued_remap_cooldown_date')->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::create('character_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('trained_level');
            $table->unsignedTinyInteger('active_level');
            $table->unsignedBigInteger('skillpoints');
            $table->unique(['character_id', 'skill_id']);
        });

        Schema::create('character_skill_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('finished_level');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('finish_date')->nullable();
            $table->unsignedBigInteger('level_start_sp')->nullable();
            $table->unsignedBigInteger('level_end_sp')->nullable();
            $table->unsignedBigInteger('training_start_sp')->nullable();
            $table->unique(['character_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_skill_queue');
        Schema::dropIfExists('character_skills');
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn([
                'total_sp', 'unallocated_sp', 'charisma', 'intelligence', 'memory',
                'perception', 'willpower', 'bonus_remaps', 'last_remap_date',
                'accrued_remap_cooldown_date', 'last_synced_at',
            ]);
        });
    }
};
