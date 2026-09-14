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

        // Amarr pays better per unit.
        Http::fake([
            'market.fuzzwork.co.uk/*station=60003760*' => Http::response([
                '34' => ['buy' => ['percentile' => 4.0], 'sell' => ['percentile' => 5.0]],
            ]),
            'market.fuzzwork.co.uk/*station=60008494*' => Http::response([
                '34' => ['buy' => ['percentile' => 5.0], 'sell' => ['percentile' => 7.0]],
            ]),
            'market.fuzzwork.co.uk/*' => Http::response([]),
        ]);

        $result = $this->app->make(HubComparisonService::class)
            ->compare($character, [34 => 1000], originSystemId: 1);

        $best = $result['best'];
        $this->assertSame('Amarr', $best->systemName);
        $this->assertNull($best->jumps); // unreachable island in this fixture

        $jita = collect($result['options'])->firstWhere('systemName', 'Jita');
        $this->assertSame(2, $jita->jumps);
        $this->assertSame([1, 2, 30000142], $jita->route);
        $this->assertGreaterThan(0, $jita->orderNet);
    }
}
