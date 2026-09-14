<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily character wealth snapshots (wallet + priced assets + orders).
        Schema::create('net_worth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->date('date');
            $table->double('wallet');
            $table->double('assets_value');
            $table->double('sell_orders_value');
            $table->double('buy_escrow');
            $table->double('implants_value');
            $table->double('total');
            $table->unique(['character_id', 'date']);
        });

        // ESI /characters/{id}/wallet/transactions — the buy/sell fills that
        // realized-trading-profit matching runs on.
        Schema::create('character_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->primary();
            $table->unsignedBigInteger('character_id')->index();
            $table->timestamp('date')->index();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('quantity');
            $table->double('unit_price');
            $table->boolean('is_buy');
            $table->unsignedBigInteger('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_transactions');
        Schema::dropIfExists('net_worth_snapshots');
    }
};
