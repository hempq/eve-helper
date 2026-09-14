<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // 'highsec' = 0.5+ only, 'highlow' = exclude null, 'all' = anywhere.
            $table->string('route_security', 12)->default('highsec');
        });

        // Carry the old boolean over: avoiding low-sec meant high-sec only.
        DB::table('characters')->where('avoid_lowsec', false)->update(['route_security' => 'all']);

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('avoid_lowsec');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->boolean('avoid_lowsec')->default(true);
        });
        DB::table('characters')->where('route_security', '!=', 'highsec')->update(['avoid_lowsec' => false]);
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('route_security');
        });
    }
};
