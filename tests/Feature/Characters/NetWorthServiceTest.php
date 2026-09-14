<?php

namespace Tests\Feature\Characters;

use App\Models\Character;
use App\Services\Characters\NetWorthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetWorthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_and_snapshots_wealth(): void
    {
        $character = Character::factory()->create(['wallet_balance' => 10_000_000]);

        DB::table('character_assets')->insert([
            ['character_id' => $character->character_id, 'item_id' => 1, 'type_id' => 34, 'quantity' => 1000,
                'location_id' => 60003760, 'location_flag' => 'Hangar', 'location_type' => 'station', 'is_singleton' => false],
        ]);
        DB::table('character_implants')->insert([
            ['character_id' => $character->character_id, 'type_id' => 10216],
        ]);
        DB::table('character_orders')->insert([
            ['order_id' => 1, 'character_id' => $character->character_id, 'type_id' => 34, 'location_id' => 60003760,
                'region_id' => 10000002, 'is_buy_order' => false, 'price' => 6.0, 'volume_remain' => 500,
                'volume_total' => 500, 'issued' => now()],
            ['order_id' => 2, 'character_id' => $character->character_id, 'type_id' => 35, 'location_id' => 60003760,
                'region_id' => 10000002, 'is_buy_order' => true, 'price' => 10.0, 'volume_remain' => 100,
                'volume_total' => 100, 'issued' => now()],
        ]);

        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '34' => ['buy' => ['percentile' => 5.0], 'sell' => ['percentile' => 6.0]],
                '10216' => ['buy' => ['percentile' => 100_000_000], 'sell' => ['percentile' => 120_000_000]],
            ]),
        ]);

        $worth = $this->app->make(NetWorthService::class)->record($character);

        // No skills -> 7.5% sales tax nets out of liquidation values.
        $this->assertEqualsWithDelta(10_000_000, $worth->wallet, 0.1);
        $this->assertEqualsWithDelta(4_625, $worth->assetsValue, 0.1);        // 1000 × 5 buy × 0.925
        $this->assertEqualsWithDelta(2_775, $worth->sellOrdersValue, 0.1);    // 500 × 6 × 0.925
        $this->assertEqualsWithDelta(1_000, $worth->buyEscrow, 0.1);          // 100 × 10
        $this->assertEqualsWithDelta(100_000_000, $worth->implantsValue, 0.1);
        $this->assertEqualsWithDelta(110_008_400, $worth->total, 0.1);

        $this->assertDatabaseHas('net_worth_snapshots', [
            'character_id' => $character->character_id,
            'date' => now()->toDateString(),
        ]);

        // Recording twice on the same day updates rather than duplicates.
        $this->app->make(NetWorthService::class)->record($character);
        $this->assertSame(1, DB::table('net_worth_snapshots')->count());
    }
}
