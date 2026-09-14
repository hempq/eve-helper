<?php

namespace Tests\Feature\Farm;

use App\Services\Farm\ConstellationTourService;
use App\Services\Farm\TargetScorerService;
use Carbon\CarbonImmutable;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstellationTourServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('regions')->insert(['region_id' => 1, 'name' => 'Fountain']);
        DB::table('constellations')->insert([
            ['constellation_id' => 1, 'region_id' => 1, 'name' => 'Pegasus'],
            ['constellation_id' => 2, 'region_id' => 1, 'name' => 'Solo'],
        ]);
        // Chain: origin(10) - 11 - 12 - 13; constellation 1 = {11,12,13};
        // 99 is a lone constellation-2 system (rank-filtered), unreachable.
        DB::table('solar_systems')->insert([
            ['system_id' => 10, 'constellation_id' => 2, 'region_id' => 1, 'name' => 'Origin', 'security' => 0.4],
            ['system_id' => 11, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Alpha', 'security' => -0.1],
            ['system_id' => 12, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Bravo', 'security' => -0.2],
            ['system_id' => 13, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Charlie', 'security' => -0.3],
            ['system_id' => 99, 'constellation_id' => 2, 'region_id' => 1, 'name' => 'Island', 'security' => -0.5],
        ]);
        foreach ([[10, 11], [11, 12], [12, 13]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/universe/system_kills')->andReturn(new EsiResponse([
            ['system_id' => 12, 'npc_kills' => 250, 'ship_kills' => 0, 'pod_kills' => 0],
        ], $expires));
        $esi->shouldReceive('get')->with('/universe/system_jumps')->andReturn(new EsiResponse([], $expires));
    }

    public function test_ranks_constellations_from_scored_systems(): void
    {
        $scored = $this->app->make(TargetScorerService::class)->score(10, maxJumps: 5);
        $ranked = $this->app->make(ConstellationTourService::class)->rank($scored);

        // Constellation 2 has only one reachable member -> filtered out.
        $this->assertCount(1, $ranked);
        $this->assertSame('Pegasus', $ranked->first()->name);
        $this->assertSame(3, $ranked->first()->systems);
        $this->assertSame(1, $ranked->first()->deadEnds); // Charlie has one gate
        $this->assertSame(250, $ranked->first()->npcKills);
    }

    public function test_tour_visits_all_members_in_jump_order(): void
    {
        $tour = $this->app->make(ConstellationTourService::class)->tour(10, 1);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($tour->systems, 'name'));
        $this->assertSame([1, 1, 1], array_column($tour->systems, 'legJumps'));
        $this->assertSame(3, $tour->totalJumps);
        $this->assertSame(1, $tour->approachJumps);
        $this->assertTrue(end($tour->systems)->deadEnd);
        $this->assertSame(250, $tour->systems[1]->npcKills);

        // Clean chain: full path has no revisits and never leaves the
        // constellation once entered.
        $this->assertSame(['Origin', 'Alpha', 'Bravo', 'Charlie'], array_column($tour->fullPath, 'name'));
        $this->assertSame(0, $tour->revisitCount);
        $this->assertSame(0, $tour->outsideCount);
    }

    public function test_leg_leaves_constellation_when_that_is_shorter(): void
    {
        // New constellation 3 = {Left(21), Right(23)} whose ONLY link is the
        // outside hub Mid(22, constellation 2).
        DB::table('constellations')->insert(['constellation_id' => 3, 'region_id' => 1, 'name' => 'Split']);
        DB::table('solar_systems')->insert([
            ['system_id' => 21, 'constellation_id' => 3, 'region_id' => 1, 'name' => 'Left', 'security' => 0.6],
            ['system_id' => 22, 'constellation_id' => 2, 'region_id' => 1, 'name' => 'Mid', 'security' => 0.6],
            ['system_id' => 23, 'constellation_id' => 3, 'region_id' => 1, 'name' => 'Right', 'security' => 0.6],
        ]);
        foreach ([[10, 21], [21, 22], [22, 23]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        $tour = $this->app->make(ConstellationTourService::class)->tour(10, 3);

        $this->assertSame(['Left', 'Right'], array_column($tour->systems, 'name'));
        $this->assertSame(3, $tour->totalJumps); // 10->21 (1) + 21->22->23 (2)

        // The full path crosses Mid, marked as an out-of-constellation hop.
        $this->assertSame(['Origin', 'Left', 'Mid', 'Right'], array_column($tour->fullPath, 'name'));
        $mid = collect($tour->fullPath)->firstWhere('name', 'Mid');
        $this->assertFalse($mid->inConstellation);
        $this->assertSame(1, $tour->outsideCount);
        $this->assertSame(0, $tour->revisitCount);
    }

    public function test_star_topology_counts_revisits(): void
    {
        // Constellation 4 = {A(31), B(32), C(33)}, each hanging off the
        // outside hub Star(30): every leg re-crosses the hub.
        DB::table('constellations')->insert(['constellation_id' => 4, 'region_id' => 1, 'name' => 'Petals']);
        DB::table('solar_systems')->insert([
            ['system_id' => 30, 'constellation_id' => 2, 'region_id' => 1, 'name' => 'Star', 'security' => 0.7],
            ['system_id' => 31, 'constellation_id' => 4, 'region_id' => 1, 'name' => 'PetalA', 'security' => 0.7],
            ['system_id' => 32, 'constellation_id' => 4, 'region_id' => 1, 'name' => 'PetalB', 'security' => 0.7],
            ['system_id' => 33, 'constellation_id' => 4, 'region_id' => 1, 'name' => 'PetalC', 'security' => 0.7],
        ]);
        foreach ([[10, 30], [30, 31], [30, 32], [30, 33]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        $tour = $this->app->make(ConstellationTourService::class)->tour(10, 4);

        // 1 (to Star) + 1 (petal) + 2+2 between petals = 6 jumps, and the
        // Star hub is re-crossed twice.
        $this->assertCount(3, $tour->systems);
        $this->assertSame(6, $tour->totalJumps);
        $this->assertSame(2, $tour->revisitCount);
        $this->assertGreaterThanOrEqual(2, $tour->outsideCount);
    }

    public function test_unknown_constellation_returns_null(): void
    {
        $this->assertNull($this->app->make(ConstellationTourService::class)->tour(10, 777));
    }
}
