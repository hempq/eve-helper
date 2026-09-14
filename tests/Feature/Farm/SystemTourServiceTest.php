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

    public function test_tours_targets_in_optimal_order_with_full_path(): void
    {
        // Targets are the two ends; the tour must pass through Bravo.
        $tour = $this->app->make(SystemTourService::class)->tour(10, [11, 13]);

        $this->assertSame(['Alpha', 'Charlie'], array_column($tour->systems, 'name'));
        $this->assertSame(3, $tour->totalJumps); // 10->11 (1) + 11->12->13 (2)
        $this->assertSame(1, $tour->approachJumps);

        // Full path includes the non-target Bravo, flagged as such.
        $this->assertSame(['Origin', 'Alpha', 'Bravo', 'Charlie'], array_column($tour->fullPath, 'name'));
        $bravo = collect($tour->fullPath)->firstWhere('name', 'Bravo');
        $this->assertFalse($bravo->isTarget);
        $this->assertSame(0, $tour->revisitCount);
    }

    public function test_origin_is_never_a_target_and_empty_returns_null(): void
    {
        $this->assertNull($this->app->make(SystemTourService::class)->tour(10, [10]));
        $this->assertNull($this->app->make(SystemTourService::class)->tour(10, []));
    }

    public function test_highsec_only_makes_nullsec_targets_unreachable(): void
    {
        // minSecurity 0.45 forbids entering Alpha/Bravo/Charlie (all < 0.45),
        // so no leg can be built.
        $tour = $this->app->make(SystemTourService::class)->tour(10, [11, 12, 13], minSecurity: 0.45);

        $this->assertNull($tour);
    }
}
