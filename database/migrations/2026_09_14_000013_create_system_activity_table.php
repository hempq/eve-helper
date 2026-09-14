<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('system_id');
            $table->unsignedInteger('npc_kills')->default(0);
            $table->unsignedInteger('ship_kills')->default(0);
            $table->unsignedInteger('pod_kills')->default(0);
            $table->unsignedInteger('ship_jumps')->default(0);
            $table->timestamp('recorded_at')->index();
            $table->unique(['system_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_activity');
    }
};
