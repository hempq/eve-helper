<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_journal', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->primary();
            $table->unsignedBigInteger('character_id')->index();
            $table->string('ref_type', 60)->index();
            $table->decimal('amount', 20, 2)->nullable();
            $table->decimal('balance', 20, 2)->nullable();
            $table->timestamp('date')->index();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('context_id_type', 40)->nullable();
            $table->string('description', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_journal');
    }
};
