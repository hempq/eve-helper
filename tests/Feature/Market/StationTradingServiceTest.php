<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\StationTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StationTradingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_flips_by_net_spread_with_fees(): void
    {
        $character = Character::factory()->create(); // no skills: tax 7.5%, broker 3%

        DB::table('item_types')->insert([
            ['type_id' => 44992, 'group_id' => 1, 'name' => 'PLEX', 'published' => true],
            ['type_id' => 34, 'group_id' => 1, 'name' => 'Tritanium', 'published' => true],
        ]);
        DB::table('hub_prices')->insert([
            // Wide spread: bid 100k, ask 130k -> cost 103k, revenue 130k×0.895 = 116.35k -> profit 13,350 (13%).
            ['station_id' => 60003760, 'type_id' => 44992, 'best_bid' => 100_000.0, 'best_ask' => 130_000.0,
                'bid_volume' => 50, 'ask_volume' => 40, 'scanned_at' => now()],
            // Thin spread that fees eat entirely: bid 100k, ask 109k.
            ['station_id' => 60003760, 'type_id' => 34, 'best_bid' => 100_000.0, 'best_ask' => 109_000.0,
                'bid_volume' => 1000, 'ask_volume' => 1000, 'scanned_at' => now()],
        ]);

        $result = $this->app->make(StationTradingService::class)
            ->find($character, 60003760, minMargin: 0.08);

        $this->assertCount(1, $result['trades']);
        $trade = $result['trades']->first();
        $this->assertSame('PLEX', $trade->name);
        $this->assertEqualsWithDelta(13_350.0, $trade->profitPerUnit, 0.5);
        $this->assertEqualsWithDelta(0.1296, $trade->margin, 0.001);
    }

    public function test_no_scan_yields_empty(): void
    {
        $character = Character::factory()->create();

        $result = $this->app->make(StationTradingService::class)->find($character, 60003760);

        $this->assertNull($result['scannedAt']);
        $this->assertTrue($result['trades']->isEmpty());
    }
}
