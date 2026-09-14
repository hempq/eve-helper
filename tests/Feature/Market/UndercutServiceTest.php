<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\UndercutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UndercutServiceTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'published' => true],
            ['type_id' => 35, 'group_id' => 18, 'name' => 'Pyerite', 'published' => true],
        ]);
        DB::table('stations')->insert([
            'station_id' => 60003760, 'system_id' => 30000142, 'name' => 'Jita IV-4 CNAP',
        ]);
    }

    private function insertOrder(int $orderId, int $typeId, float $price, bool $isBuy = false): void
    {
        DB::table('character_orders')->insert([
            'order_id' => $orderId, 'character_id' => $this->character->character_id,
            'type_id' => $typeId, 'location_id' => 60003760, 'region_id' => 10000002,
            'is_buy_order' => $isBuy, 'price' => $price,
            'volume_remain' => 1000, 'volume_total' => 2000, 'issued' => now(),
        ]);
    }

    public function test_flags_undercut_sell_order_and_ignores_other_stations(): void
    {
        $this->insertOrder(101, 34, price: 6.00);

        Http::fake([
            'esi.evetech.net/markets/10000002/orders*' => Http::response([
                ['order_id' => 101, 'location_id' => 60003760, 'price' => 6.00],  // own order
                ['order_id' => 202, 'location_id' => 60003760, 'price' => 5.50],  // the undercutter
                ['order_id' => 303, 'location_id' => 60011866, 'price' => 4.00],  // other station -> ignore
            ], 200, ['Expires' => now()->addMinutes(5)->toRfc7231String()]),
        ]);

        $rows = $this->app->make(UndercutService::class)->check($this->character);

        $row = $rows->firstWhere('orderId', 101);
        $this->assertTrue($row->undercut);
        $this->assertSame(5.50, $row->bestPrice);
        $this->assertSame('Jita IV-4 CNAP', $row->locationName);
        $this->assertNotNull($row->relistCost);
    }

    public function test_best_priced_sell_order_is_not_flagged(): void
    {
        $this->insertOrder(102, 34, price: 5.00);

        Http::fake([
            'esi.evetech.net/markets/*' => Http::response([
                ['order_id' => 102, 'location_id' => 60003760, 'price' => 5.00],
                ['order_id' => 203, 'location_id' => 60003760, 'price' => 5.10],
            ], 200, ['Expires' => now()->addMinutes(5)->toRfc7231String()]),
        ]);

        $row = $this->app->make(UndercutService::class)->check($this->character)->first();

        $this->assertFalse($row->undercut);
    }

    public function test_buy_order_is_undercut_by_higher_bid(): void
    {
        $this->insertOrder(103, 35, price: 10.00, isBuy: true);

        Http::fake([
            'esi.evetech.net/markets/*' => Http::response([
                ['order_id' => 103, 'location_id' => 60003760, 'price' => 10.00],
                ['order_id' => 204, 'location_id' => 60003760, 'price' => 10.50], // outbids us
            ], 200, ['Expires' => now()->addMinutes(5)->toRfc7231String()]),
        ]);

        $row = $this->app->make(UndercutService::class)->check($this->character)->first();

        $this->assertTrue($row->undercut);
        $this->assertSame(10.50, $row->bestPrice);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'order_type=buy'));
    }

    public function test_structure_order_undercut_via_structure_book(): void
    {
        // Order in an Upwell structure (location id above the structure floor).
        DB::table('character_orders')->insert([
            'order_id' => 500, 'character_id' => $this->character->character_id,
            'type_id' => 34, 'location_id' => 1_035_000_000_001, 'region_id' => 10000002,
            'is_buy_order' => false, 'price' => 6.0,
            'volume_remain' => 100, 'volume_total' => 100, 'issued' => now(),
        ]);

        Http::fake([
            'esi.evetech.net/markets/structures/1035000000001*' => Http::response([
                ['order_id' => 500, 'type_id' => 34, 'is_buy_order' => false, 'price' => 6.0],  // own
                ['order_id' => 600, 'type_id' => 34, 'is_buy_order' => false, 'price' => 5.4],  // undercutter
                ['order_id' => 700, 'type_id' => 34, 'is_buy_order' => true, 'price' => 4.0],   // buy side, ignore
            ], 200, ['X-Pages' => '1', 'Expires' => now()->addMinutes(5)->toRfc7231String()]),
        ]);

        $row = $this->app->make(UndercutService::class)->check($this->character)->firstWhere('orderId', 500);

        $this->assertTrue($row->isStructure);
        $this->assertTrue($row->undercut);
        $this->assertSame(5.4, $row->bestPrice);
    }

    public function test_no_orders_returns_empty(): void
    {
        $this->assertTrue($this->app->make(UndercutService::class)->check($this->character)->isEmpty());
    }
}
