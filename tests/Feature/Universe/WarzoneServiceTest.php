<?php

namespace Tests\Feature\Universe;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Universe\WarzoneService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarzoneServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('regions')->insert([['region_id' => 1, 'name' => 'Placid']]);
        DB::table('constellations')->insert([['constellation_id' => 10, 'region_id' => 1, 'name' => 'Josmaert']]);
        DB::table('solar_systems')->insert([
            ['system_id' => 100, 'constellation_id' => 10, 'region_id' => 1, 'name' => 'Home', 'security' => 0.9],
            ['system_id' => 101, 'constellation_id' => 10, 'region_id' => 1, 'name' => 'Staging', 'security' => 0.5],
            ['system_id' => 102, 'constellation_id' => 10, 'region_id' => 1, 'name' => 'Frontline', 'security' => 0.3],
        ]);
        foreach ([[100, 101], [101, 102]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }
    }

    public function test_incursions_enriched_with_names_and_distance(): void
    {
        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/incursions/')->andReturn(new EsiResponse([
            [
                'constellation_id' => 10,
                'faction_id' => 500019,
                'has_boss' => true,
                'infested_solar_systems' => [101, 102],
                'influence' => 0.75,
                'staging_solar_system_id' => 101,
                'state' => 'established',
                'type' => 'Incursion',
            ],
        ], $expires));

        $incursions = $this->app->make(WarzoneService::class)->incursions(100);

        $this->assertCount(1, $incursions);
        $inc = $incursions[0];
        $this->assertSame('Josmaert', $inc->constellation);
        $this->assertSame('Placid', $inc->region);
        $this->assertSame("Sansha's Nation", $inc->faction);
        $this->assertSame('Staging', $inc->staging);
        $this->assertSame(1, $inc->distance);
        $this->assertSame(2, $inc->systemCount);
        $this->assertTrue($inc->hasBoss);
    }

    public function test_faction_warfare_summary_and_contested_list(): void
    {
        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/fw/systems/')->andReturn(new EsiResponse([
            [
                'solar_system_id' => 102,
                'owner_faction_id' => 500004,
                'occupier_faction_id' => 500001,
                'contested' => 'contested',
                'victory_points' => 1500,
                'victory_points_threshold' => 3000,
            ],
            [
                'solar_system_id' => 101,
                'owner_faction_id' => 500004,
                'occupier_faction_id' => 500004,
                'contested' => 'uncontested',
                'victory_points' => 0,
                'victory_points_threshold' => 3000,
            ],
        ], $expires));

        $fw = $this->app->make(WarzoneService::class)->factionWarfare(100);

        $this->assertSame(1, $fw->summary[500001]['systems']);
        $this->assertSame(1, $fw->summary[500001]['contested']);
        $this->assertSame(1, $fw->summary[500004]['systems']);
        $this->assertSame(0, $fw->summary[500004]['contested']);

        $this->assertCount(1, $fw->contested);
        $this->assertSame('Frontline', $fw->contested[0]->name);
        $this->assertSame('Caldari State', $fw->contested[0]->occupier);
        $this->assertSame('Gallente Federation', $fw->contested[0]->owner);
        $this->assertEqualsWithDelta(50.0, $fw->contested[0]->contestedPct, 0.1);
        $this->assertSame(2, $fw->contested[0]->distance);
    }

    public function test_esi_failure_degrades_gracefully(): void
    {
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->andThrow(new EsiRequestFailed('down'));

        $service = $this->app->make(WarzoneService::class);

        $this->assertSame([], $service->incursions(100));
        $this->assertSame([], $service->factionWarfare(100)->summary);
    }
}
