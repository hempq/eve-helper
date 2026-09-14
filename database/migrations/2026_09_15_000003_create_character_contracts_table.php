<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->primary();
            $table->unsignedBigInteger('character_id')->index();
            $table->string('type', 20);       // item_exchange | auction | courier | ...
            $table->string('status', 20);     // outstanding | in_progress | finished | ...
            $table->string('title')->nullable();
            $table->decimal('price', 20, 2)->default(0);
            $table->decimal('reward', 20, 2)->default(0);
            $table->decimal('collateral', 20, 2)->default(0);
            $table->decimal('volume', 20, 2)->nullable();
            $table->boolean('for_corporation')->default(false);
            $table->timestamp('date_issued')->nullable();
            $table->timestamp('date_expired')->nullable();
            $table->timestamp('date_completed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_contracts');
    }
};
