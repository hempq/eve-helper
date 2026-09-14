<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Route legs detour around incursions, FW frontlines and live
            // gank activity when enabled.
            $table->boolean('hazard_avoidance')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('hazard_avoidance');
        });
    }
};
