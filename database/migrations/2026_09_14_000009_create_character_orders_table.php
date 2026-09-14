<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('region_id');
            $table->boolean('is_buy_order');
            $table->decimal('price', 20, 2);
            $table->unsignedBigInteger('volume_remain');
            $table->unsignedBigInteger('volume_total');
            $table->timestamp('issued');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_orders');
    }
};
