<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id');
            $table->unsignedBigInteger('type_id');
            $table->decimal('best_bid', 20, 2)->nullable();
            $table->decimal('best_ask', 20, 2)->nullable();
            $table->unsignedBigInteger('bid_volume')->default(0);
            $table->unsignedBigInteger('ask_volume')->default(0);
            $table->timestamp('scanned_at');
            $table->primary(['station_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_prices');
    }
};
