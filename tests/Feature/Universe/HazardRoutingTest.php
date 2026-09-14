<?php

namespace Tests\Feature\Universe;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Universe\HazardService;
use App\Services\Universe\RouteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HazardRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_detours_around_penalized_systems_when_worth_it(): void
    {
        // Diamond: 1-2-4 (short, but 2 is hazardous) vs 1-3a-3b-4 (one jump longer).
        DB::table('solar_systems')->insert([
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'A', 'security' => 0.9],
            ['system_id' => 2, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Hazard', 'security' => 0.9],
            ['system_id' => 31, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Detour1', 'security' => 0.9],
            ['system_id' => 32, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Detour2', 'security' => 0.9],
            ['system_id' => 4, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'B', 'security' => 0.9],
        ]);
        foreach ([[1, 2], [2, 4], [1, 31], [31, 32], [32, 4]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        $routes = $this->app->make(RouteService::class);

        // Without penalties the short way wins; with them the detour does.
        $this->assertSame([1, 2, 4], $routes->route(1, 4));
        $this->assertSame([1, 31, 32, 4], $routes->route(1, 4, extraCosts: [2 => 40.0]));

        // A huge penalty is still crossed when there is no other way.
        DB::table('system_jumps')->whereIn('from_system_id', [31, 32])->delete();
        DB::table('system_jumps')->whereIn('to_system_id', [31, 32])->delete();
        $fresh = $this->app->make(RouteService::class); // reloads the graph
        $this->assertSame([1, 2, 4], $fresh->route(1, 4, extraCosts: [2 => 40.0]));
    }

    public function test_hazard_costs_layer_incursions_fw_and_kills(): void
    {
        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/incursions/')->andReturn(new EsiResponse([
            ['constellation_id' => 1, 'infested_solar_systems' => [101, 102]],
        ], $expires));
        $esi->shouldReceive('get')->with('/fw/systems/')->andReturn(new EsiResponse([
            ['solar_system_id' => 201, 'contested' => 'contested'],
            ['solar_system_id' => 202, 'contested' => 'uncontested'],
        ], $expires));
        $esi->shouldReceive('get')->with('/universe/system_kills')->andReturn(new EsiResponse([
            ['system_id' => 301, 'npc_kills' => 0, 'ship_kills' => 4, 'pod_kills' => 2],
        ], $expires));

        $costs = $this->app->make(HazardService::class)->costs();

        $this->assertEqualsWithDelta(40.0, $costs[101], 0.01);
        $this->assertEqualsWithDelta(8.0, $costs[201], 0.01);
        $this->assertArrayNotHasKey(202, $costs);
        $this->assertEqualsWithDelta(9.0, $costs[301], 0.01); // 6 kills × 1.5
    }
}
