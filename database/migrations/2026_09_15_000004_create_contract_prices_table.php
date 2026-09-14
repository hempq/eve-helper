<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-type asking prices of public single-item item_exchange
        // contracts, aggregated from the EVE Ref public-contracts snapshot.
        Schema::create('contract_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('type_id')->primary();
            $table->unsignedInteger('sample_count');
            $table->double('min_price');
            $table->double('p20_price');
            $table->double('median_price');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_prices');
    }
};
