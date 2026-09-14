<?php

namespace Tests\Feature\Farm;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Farm\TargetScorerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetScorerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('regions')->insert([
            ['region_id' => 1, 'name' => 'Fountain'],   // Serpentis
            ['region_id' => 2, 'name' => 'Venal'],      // Guristas
        ]);
        DB::table('constellations')->insert([
            ['constellation_id' => 1, 'region_id' => 1, 'name' => 'C-A'],
            ['constellation_id' => 2, 'region_id' => 2, 'name' => 'C-B'],
        ]);
        DB::table('solar_systems')->insert([
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Home', 'security' => 0.5],
            ['system_id' => 2, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'QuietNull', 'security' => -0.3],
            ['system_id' => 3, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'CampedNull', 'security' => -0.2],
            ['system_id' => 4, 'constellation_id' => 2, 'region_id' => 2, 'name' => 'GuristaLand', 'security' => -0.5],
            ['system_id' => 9, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'FarAway', 'security' => -0.1],
        ]);
        foreach ([[1, 2], [2, 3], [3, 4]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }
        // FarAway (9) is not connected at all.

        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/universe/system_kills')->andReturn(new EsiResponse([
            ['system_id' => 2, 'npc_kills' => 400, 'ship_kills' => 0, 'pod_kills' => 0],
            ['system_id' => 3, 'npc_kills' => 500, 'ship_kills' => 6, 'pod_kills' => 2],
            ['system_id' => 4, 'npc_kills' => 300, 'ship_kills' => 0, 'pod_kills' => 0],
        ], $expires));
        $esi->shouldReceive('get')->with('/universe/system_jumps')->andReturn(new EsiResponse([
            ['system_id' => 3, 'ship_jumps' => 900],
        ], $expires));
    }

    public function test_quiet_system_in_active_constellation_beats_camped_one(): void
    {
        $targets = $this->app->make(TargetScorerService::class)->score(1, maxJumps: 5);

        $quiet = $targets->firstWhere('name', 'QuietNull');
        $camped = $targets->firstWhere('name', 'CampedNull');

        // Same constellation => same spawn evidence; the camped one loses on
        // player kills, own NPC competition and traffic.
        $this->assertSame($quiet->constellationNpcKills, $camped->constellationNpcKills);
        $this->assertSame(8, $camped->playerKills);
        $this->assertGreaterThan($camped->score, $quiet->score);

        // Dead-end detection: GuristaLand hangs on a single gate.
        $gurista = $targets->firstWhere('name', 'GuristaLand');
        $this->assertTrue($gurista->deadEnd);
        $this->assertSame(1, $gurista->gates);
        $this->assertFalse($quiet->deadEnd);

        // Unreachable system is not listed; origin itself excluded.
        $this->assertNull($targets->firstWhere('name', 'FarAway'));
        $this->assertNull($targets->firstWhere('name', 'Home'));
    }

    public function test_own_logged_sites_boost_the_constellation(): void
    {
        $character = \App\Models\Character::factory()->create();
        $scorer = $this->app->make(TargetScorerService::class);

        $without = $scorer->score(1, maxJumps: 5)->firstWhere('name', 'QuietNull');

        DB::table('signatures')->insert([
            'character_id' => $character->character_id, 'system_id' => 2,
            'sig_id' => 'AAA-111', 'sig_group' => 'Cosmic Anomaly', 'category' => 'Combat Site',
            'status' => 'done', 'first_seen' => now()->subDays(2), 'last_seen' => now()->subDays(2),
        ]);

        $with = $scorer->score(1, maxJumps: 5, character: $character)->firstWhere('name', 'QuietNull');

        $this->assertSame(1, $with->ownSites);
        $this->assertEqualsWithDelta($without->score + 2, $with->score, 0.11);
    }

    public function test_faction_and_security_filters(): void
    {
        $scorer = $this->app->make(TargetScorerService::class);

        $guristas = $scorer->score(1, maxJumps: 5, faction: 'Guristas');
        $this->assertSame(['GuristaLand'], $guristas->pluck('name')->all());

        $nullOnly = $scorer->score(1, maxJumps: 5, securityBand: 'nullsec');
        $this->assertNotNull($nullOnly->firstWhere('name', 'QuietNull'));
        $this->assertNull($nullOnly->firstWhere('name', 'Home'));
    }

    public function test_extra_edges_extend_the_range(): void
    {
        // FarAway connects only through a wormhole edge from Home.
        $targets = $this->app->make(TargetScorerService::class)
            ->score(1, maxJumps: 3, extraEdges: [[1, 9]]);

        $far = $targets->firstWhere('name', 'FarAway');
        $this->assertNotNull($far);
        $this->assertSame(1, $far->distance);
    }
}
