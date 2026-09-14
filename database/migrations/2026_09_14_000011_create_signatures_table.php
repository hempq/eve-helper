<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('system_id')->index();
            $table->string('sig_id', 10);           // e.g. "VOB-799"; escalations get a slug
            $table->string('sig_group', 30);        // Cosmic Signature / Cosmic Anomaly / Escalation
            $table->string('category', 40)->nullable();  // Combat Site, Data Site, Wormhole...
            $table->string('name')->nullable();
            $table->float('signal')->nullable();    // scan strength %
            $table->string('status', 12)->default('active'); // active | done | gone
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // escalations: +24h
            $table->unique(['character_id', 'system_id', 'sig_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
