<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\HubPriceScanService;
use App\Services\Market\TradeFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TradeFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_scan_extracts_best_bid_ask_with_depth(): void
    {
        Http::fake([
            'esi.evetech.net/markets/10000002/orders*page=1*' => Http::response([
                ['type_id' => 34, 'location_id' => 60003760, 'is_buy_order' => false, 'price' => 5.0, 'volume_remain' => 1000],
                ['type_id' => 34, 'location_id' => 60003760, 'is_buy_order' => false, 'price' => 5.5, 'volume_remain' => 500],
                ['type_id' => 34, 'location_id' => 99999, 'is_buy_order' => false, 'price' => 1.0, 'volume_remain' => 9], // elsewhere
            ], 200, ['X-Pages' => '2', 'Expires' => now()->addMinutes(5)->toRfc7231String()]),
            'esi.evetech.net/markets/10000002/orders*page=2*' => Http::response([
                ['type_id' => 34, 'location_id' => 60003760, 'is_buy_order' => true, 'price' => 4.0, 'volume_remain' => 800],
                ['type_id' => 34, 'location_id' => 60003760, 'is_buy_order' => true, 'price' => 4.2, 'volume_remain' => 200],
            ], 200, ['X-Pages' => '2', 'Expires' => now()->addMinutes(5)->toRfc7231String()]),
        ]);

        $count = $this->app->make(HubPriceScanService::class)->scan(10000002, 60003760);

        $this->assertSame(1, $count);
        $row = DB::table('hub_prices')->where('station_id', 60003760)->first();
        $this->assertEqualsWithDelta(5.0, (float) $row->best_ask, 0.001);  // lowest sell
        $this->assertEqualsWithDelta(4.2, (float) $row->best_bid, 0.001);  // highest buy
        $this->assertSame(1500, (int) $row->ask_volume);
        $this->assertSame(1000, (int) $row->bid_volume);
    }

    public function test_finder_ranks_profitable_flips_with_constraints(): void
    {
        $character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'volume' => 0.01, 'published' => true],
            ['type_id' => 44, 'group_id' => 18, 'name' => 'Enriched Uranium', 'volume' => 0.15, 'published' => true],
        ]);
        DB::table('solar_systems')->insert([
            ['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9],
            ['system_id' => 30002187, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Amarr', 'security' => 1.0],
        ]);
        DB::table('system_jumps')->insert([
            ['from_system_id' => 30000142, 'to_system_id' => 30002187],
            ['from_system_id' => 30002187, 'to_system_id' => 30000142],
        ]);

        $now = now();
        DB::table('hub_prices')->insert([
            // Uranium: cheap in Jita, strong bids in Amarr -> profitable flip.
            ['station_id' => 60003760, 'type_id' => 44, 'best_bid' => 900, 'best_ask' => 1000,
                'bid_volume' => 500, 'ask_volume' => 40_000, 'scanned_at' => $now],
            ['station_id' => 60008494, 'type_id' => 44, 'best_bid' => 2000, 'best_ask' => 2400,
                'bid_volume' => 30_000, 'ask_volume' => 100, 'scanned_at' => $now],
            // Tritanium: no margin after tax.
            ['station_id' => 60003760, 'type_id' => 34, 'best_bid' => 4.9, 'best_ask' => 5.0,
                'bid_volume' => 1000, 'ask_volume' => 1000, 'scanned_at' => $now],
            ['station_id' => 60008494, 'type_id' => 34, 'best_bid' => 5.05, 'best_ask' => 5.2,
                'bid_volume' => 1000, 'ask_volume' => 1000, 'scanned_at' => $now],
        ]);

        $result = $this->app->make(TradeFinderService::class)->find(
            $character, cargoM3: 3000, budget: 25_000_000,
        );

        $this->assertCount(1, $result['trades']);
        $trade = $result['trades'][0];

        $this->assertSame('Enriched Uranium', $trade->name);
        $this->assertSame('Jita', $trade->from);
        $this->assertSame('Amarr', $trade->to);

        // Caps: depth 30k bids / 40k asks, budget 25M/1000 = 25k, cargo 3000/0.15 = 20k -> 20k wins.
        $this->assertSame(20_000, $trade->quantity);
        $this->assertSame(1, $trade->jumps);
        // Profit: (2000*(1-0.075) - 1000) * 20000 = 17M.
        $this->assertEqualsWithDelta((2000 * 0.925 - 1000) * 20000, $trade->profit, 1.0);
        $this->assertEqualsWithDelta($trade->profit, $trade->iskPerJump, 1.0);
    }

    public function test_empty_scan_reports_no_data(): void
    {
        $result = $this->app->make(TradeFinderService::class)->find(Character::factory()->create());

        $this->assertNull($result['scannedAt']);
        $this->assertTrue($result['trades']->isEmpty());
    }
}
