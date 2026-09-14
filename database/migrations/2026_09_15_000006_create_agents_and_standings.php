<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NPC mission agents from the SDE (agtAgents).
        Schema::create('agents', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->primary();
            $table->unsignedInteger('division_id');
            $table->unsignedBigInteger('corporation_id')->index();
            $table->unsignedBigInteger('location_id')->index(); // station id
            $table->unsignedTinyInteger('level');
            $table->unsignedTinyInteger('agent_type_id'); // 2=basic, 4=research
            $table->boolean('is_locator')->default(false);
        });

        Schema::create('npc_corporations', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('faction_id')->nullable()->index();
        });

        Schema::create('factions', function (Blueprint $table) {
            $table->unsignedBigInteger('faction_id')->primary();
            $table->string('name');
        });

        Schema::create('npc_divisions', function (Blueprint $table) {
            $table->unsignedInteger('division_id')->primary();
            $table->string('name');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->nullable();
        });

        // ESI /characters/{id}/standings (agent | npc_corp | faction).
        Schema::create('character_standings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('from_id');
            $table->string('from_type', 20);
            $table->double('standing');
            $table->unique(['character_id', 'from_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_standings');
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('corporation_id');
        });
        Schema::dropIfExists('npc_divisions');
        Schema::dropIfExists('factions');
        Schema::dropIfExists('npc_corporations');
        Schema::dropIfExists('agents');
    }
};
