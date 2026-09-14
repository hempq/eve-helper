<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->string('type', 40);
            $table->string('dedupe_key')->index(); // one live alert per condition
            $table->string('message', 300);
            $table->string('url')->nullable();
            $table->string('severity', 12)->default('info'); // info|warn|urgent
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
