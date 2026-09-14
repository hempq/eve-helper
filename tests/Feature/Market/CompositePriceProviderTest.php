<?php

namespace Tests\Feature\Market;

use App\Services\Market\PriceProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompositePriceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_ask_fills_in_where_the_market_has_nothing(): void
    {
        // Fuzzwork knows Tritanium (34); the deadspace module (19406) has no
        // order-book price but has a contract ask.
        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '34' => ['buy' => ['percentile' => 5.0], 'sell' => ['percentile' => 6.0]],
            ]),
        ]);
        DB::table('contract_prices')->insert([
            'type_id' => 19406, 'sample_count' => 4, 'min_price' => 90_000_000,
            'p20_price' => 100_000_000, 'median_price' => 120_000_000, 'updated_at' => now(),
        ]);

        $prices = $this->app->make(PriceProviderInterface::class)->prices(60003760, [34, 19406, 777]);

        $this->assertEqualsWithDelta(6.0, $prices[34]['sell'], 0.001);
        $this->assertEqualsWithDelta(100_000_000.0, $prices[19406]['sell'], 0.1);
        $this->assertEqualsWithDelta(100_000_000.0, $prices[19406]['buy'], 0.1);
        $this->assertArrayNotHasKey(777, $prices); // nothing anywhere
    }
}
