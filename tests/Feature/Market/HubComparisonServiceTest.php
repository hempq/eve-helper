<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\HubComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_hubs_by_order_net_and_attaches_jumps(): void
    {
        $character = Character::factory()->create();

        DB::table('item_types')->insert([
            'type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'volume' => 0.01, 'published' => true,
        ]);

        // Minimal universe: origin(1) - mid(2) - Jita(30000142); Amarr unreachable.
        DB::table('solar_systems')->insert([
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Origin', 'security' => 0.9],
            ['system_id' => 2, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Mid', 'security' => 0.9],
            ['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9],
            ['system_id' => 30002187, 'constellation_id' => 2, 'region_id' => 2, 'name' => 'Amarr', 'security' => 1.0],
        ]);
        foreach ([[1, 2], [2, 30000142]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        // Amarr wins on sell orders; Jita wins on buy orders (instant).
        Http::fake([
            'market.fuzzwork.co.uk/*station=60003760*' => Http::response([
                '34' => ['buy' => ['percentile' => 6.0], 'sell' => ['percentile' => 5.0]],
            ]),
            'market.fuzzwork.co.uk/*station=60008494*' => Http::response([
                '34' => ['buy' => ['percentile' => 5.0], 'sell' => ['percentile' => 7.0]],
            ]),
            'market.fuzzwork.co.uk/*' => Http::response([]),
        ]);

        $service = $this->app->make(HubComparisonService::class);

        $byOrder = $service->compare($character, [34 => 1000], originSystemId: 1);
        $this->assertSame('Amarr', $byOrder['best']->systemName);
        $this->assertNull($byOrder['best']->jumps); // unreachable island in this fixture

        $jita = collect($byOrder['options'])->firstWhere('systemName', 'Jita');
        $this->assertSame(2, $jita->jumps);
        $this->assertSame([1, 2, 30000142], $jita->route);
        $this->assertGreaterThan(0, $jita->orderNet);

        // Ranked by instant (hitting buy orders) the best hub flips to Jita.
        $byInstant = $service->compare($character, [34 => 1000], originSystemId: 1, metric: 'instant');
        $this->assertSame('Jita', $byInstant['best']->systemName);
        $this->assertGreaterThan($byInstant['best']->orderNet, $byInstant['best']->instantNet);
    }
}
