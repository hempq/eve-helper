<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\SellTripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SellTripPlannerTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'volume' => 0.01, 'published' => true],
            ['type_id' => 44, 'group_id' => 18, 'name' => 'Enriched Uranium', 'volume' => 0.15, 'published' => true],
        ]);

        // Universe: Jita is a 1-jump SPUR off origin; Amarr is 3 jumps the
        // other way — so visiting Jita is a real detour, not on the way.
        DB::table('solar_systems')->insert([
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Origin', 'security' => 0.9],
            ['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9],
            ['system_id' => 7, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'MidA', 'security' => 0.9],
            ['system_id' => 8, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'MidB', 'security' => 0.9],
            ['system_id' => 30002187, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Amarr', 'security' => 1.0],
        ]);
        foreach ([[1, 30000142], [1, 7], [7, 8], [8, 30002187]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }
    }

    private function fakePrices(): void
    {
        // Tritanium best in Jita; Uranium far better in Amarr. Other hubs: nothing.
        Http::fake([
            'market.fuzzwork.co.uk/*station=60003760*' => Http::response([
                '34' => ['buy' => ['percentile' => 5.0], 'sell' => ['percentile' => 6.0]],
                '44' => ['buy' => ['percentile' => 900.0], 'sell' => ['percentile' => 1000.0]],
            ]),
            'market.fuzzwork.co.uk/*station=60008494*' => Http::response([
                '34' => ['buy' => ['percentile' => 3.0], 'sell' => ['percentile' => 4.0]],
                '44' => ['buy' => ['percentile' => 1800.0], 'sell' => ['percentile' => 2000.0]],
            ]),
            'market.fuzzwork.co.uk/*' => Http::response([]),
        ]);
    }

    public function test_plans_multi_stop_trip_in_optimal_order(): void
    {
        $this->fakePrices();

        // "Any gain" mode: both stops kept, ordered Jita spur first.
        $plan = $this->app->make(SellTripPlanner::class)->plan(
            $this->character, [34 => 10000, 44 => 100_000], originSystemId: 1, iskPerJump: 0,
        );

        $this->assertCount(2, $plan->stops);
        $this->assertSame(['Jita', 'Amarr'], array_column($plan->stops, 'systemName'));
        $this->assertSame(1, $plan->stops[0]->jumpsFromPrevious);
        $this->assertSame(4, $plan->stops[1]->jumpsFromPrevious); // back through origin
        $this->assertSame(5, $plan->totalJumps);

        // Tritanium sells at Jita, uranium at Amarr.
        $this->assertSame('Tritanium', $plan->stops[0]->items[0]->name);
        $this->assertSame('Enriched Uranium', $plan->stops[1]->items[0]->name);

        // Multi-stop beats the single-hub baseline.
        $this->assertGreaterThan($plan->singleHubNet, $plan->totalNet);
        $this->assertSame('Amarr', $plan->singleHubName);
        $this->assertGreaterThan(0, $plan->extraOverSingleHub());
    }

    public function test_expensive_jumps_collapse_the_trip_to_one_stop(): void
    {
        $this->fakePrices();

        // Tiny tritanium value: the Jita detour cannot pay for itself when a
        // jump must earn 10M.
        $plan = $this->app->make(SellTripPlanner::class)->plan(
            $this->character, [34 => 1000, 44 => 100_000], originSystemId: 1, iskPerJump: 10_000_000,
        );

        $this->assertCount(1, $plan->stops);
        $this->assertSame('Amarr', $plan->stops[0]->systemName);

        // The tritanium was reassigned to the remaining stop, not dropped.
        $names = array_column($plan->stops[0]->items, 'name');
        $this->assertContains('Tritanium', $names);
        $this->assertContains('Enriched Uranium', $names);
        $this->assertSame([], $plan->unsellableNames);
    }

    public function test_unknown_origin_still_produces_a_plan_without_jumps(): void
    {
        $this->fakePrices();

        $plan = $this->app->make(SellTripPlanner::class)->plan(
            $this->character, [34 => 10000, 44 => 100_000], originSystemId: null,
        );

        $this->assertNotNull($plan);
        $this->assertNull($plan->totalJumps);
        $this->assertNotEmpty($plan->stops);
    }

    public function test_returns_null_when_nothing_sellable(): void
    {
        Http::fake(['market.fuzzwork.co.uk/*' => Http::response([])]);

        $plan = $this->app->make(SellTripPlanner::class)->plan(
            $this->character, [34 => 10], originSystemId: 1,
        );

        $this->assertNull($plan);
    }
}
