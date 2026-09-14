<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->string('name');
            $table->string('owner_hash');
            $table->json('scopes');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
