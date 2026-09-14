<?php

namespace Tests\Feature\Farm;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Farm\SystemTourService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemTourServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chain: 10(origin) - 11 - 12 - 13.
        DB::table('solar_systems')->insert([
            ['system_id' => 10, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Origin', 'security' => 0.5],
            ['system_id' => 11, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Alpha', 'security' => -0.1],
            ['system_id' => 12, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Bravo', 'security' => -0.2],
            ['system_id' => 13, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Charlie', 'security' => -0.3],
        ]);
        foreach ([[10, 11], [11, 12], [12, 13]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/universe/system_kills')->andReturn(new EsiResponse([], $expires));
        $esi->shouldReceive('get')->with('/universe/system_jumps')->andReturn(new EsiResponse([], $expires));
    }

    public function test_tours_candidates_in_optimal_order_with_full_path(): void
    {
        // Two ends chosen; the tour must pass through Bravo.
        $tour = $this->app->make(SystemTourService::class)->tour(10, [11 => 5.0, 13 => 5.0], count: 2);

        $this->assertSame(['Alpha', 'Charlie'], array_column($tour->systems, 'name'));
        $this->assertSame(3, $tour->totalJumps); // 10->11 (1) + 11->12->13 (2)
        $this->assertSame(1, $tour->approachJumps);

        // Full path includes the non-target Bravo, flagged as such.
        $this->assertSame(['Origin', 'Alpha', 'Bravo', 'Charlie'], array_column($tour->fullPath, 'name'));
        $bravo = collect($tour->fullPath)->firstWhere('name', 'Bravo');
        $this->assertFalse($bravo->isTarget);
        $this->assertSame(0, $tour->revisitCount);
    }

    public function test_prioritizes_score_per_jump(): void
    {
        // Charlie (far, 3 jumps) scores high; Alpha (near, 1 jump) scores low.
        // With count 1, score-per-jump picks Alpha (low score but cheap) only
        // if its ratio wins; make Charlie's score high enough to win the detour.
        $tour = $this->app->make(SystemTourService::class)
            ->tour(10, [11 => 2.0, 13 => 100.0], count: 1);

        $this->assertSame(['Charlie'], array_column($tour->systems, 'name'));

        // Flip it: a cheap nearby system beats a far one of similar score
        // (its extra 2 detour jumps cost more than the 1-point score edge).
        $tour = $this->app->make(SystemTourService::class)
            ->tour(10, [11 => 10.0, 13 => 11.0], count: 1);
        $this->assertSame(['Alpha'], array_column($tour->systems, 'name'));
    }

    public function test_selection_search_swaps_in_a_distant_high_value_system(): void
    {
        // Five near spokes (1 jump, score 3.0, desirability 9) crowd the
        // greedy restricted candidate list, so no construction ever proposes
        // Charlie (3 jumps, score 5.1, desirability 8.67) — yet Charlie's
        // tour value (5.1 - 3) beats a spoke's (3.0 - 1). Only the
        // selection-improving swap can put it in the tour.
        $spokes = [];
        foreach ([21, 22, 23, 24, 25] as $id) {
            DB::table('solar_systems')->insert([
                ['system_id' => $id, 'constellation_id' => 1, 'region_id' => 1, 'name' => "Spoke {$id}", 'security' => -0.1],
            ]);
            DB::table('system_jumps')->insert([
                ['from_system_id' => 10, 'to_system_id' => $id],
                ['from_system_id' => $id, 'to_system_id' => 10],
            ]);
            $spokes[$id] = 3.0;
        }

        $tour = $this->app->make(SystemTourService::class)
            ->tour(10, [...$spokes, 13 => 5.1], count: 1);

        $this->assertSame(['Charlie'], array_column($tour->systems, 'name'));
    }

    public function test_count_caps_the_number_of_stops(): void
    {
        $tour = $this->app->make(SystemTourService::class)
            ->tour(10, [11 => 5.0, 12 => 5.0, 13 => 5.0], count: 2);

        $this->assertCount(2, $tour->systems);
    }

    public function test_empty_and_highsec_only_return_null(): void
    {
        $this->assertNull($this->app->make(SystemTourService::class)->tour(10, [10 => 5.0]));
        $this->assertNull($this->app->make(SystemTourService::class)->tour(10, []));

        // minSecurity 0.45 forbids entering the nullsec chain.
        $this->assertNull($this->app->make(SystemTourService::class)
            ->tour(10, [11 => 5.0, 12 => 5.0, 13 => 5.0], minSecurity: 0.45));
    }
}
