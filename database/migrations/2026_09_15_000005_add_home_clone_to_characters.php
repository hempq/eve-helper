<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Death (home) clone location, from /characters/{id}/clones.
            $table->unsignedBigInteger('home_location_id')->nullable();
            $table->string('home_location_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['home_location_id', 'home_location_type']);
        });
    }
};
