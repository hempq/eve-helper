<?php

namespace Tests\Feature\Farm;

use App\Models\Character;
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
            ['region_id' => 1, 'name' => 'Fountain'],   // Serpentis space
            ['region_id' => 2, 'name' => 'Domain'],
        ]);
        DB::table('constellations')->insert([
            ['constellation_id' => 1, 'region_id' => 1, 'name' => 'C-A'],
            ['constellation_id' => 9, 'region_id' => 2, 'name' => 'Other'],
        ]);
        DB::table('solar_systems')->insert([
            // Fountain, constellation C-A (nullsec):
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Origin', 'security' => -0.3],
            ['system_id' => 2, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Quiet', 'security' => -0.4],
            ['system_id' => 3, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Camped', 'security' => -0.2],
            ['system_id' => 4, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'DeadEnd', 'security' => -0.5],
            // A different region — must never appear:
            ['system_id' => 99, 'constellation_id' => 9, 'region_id' => 2, 'name' => 'Elsewhere', 'security' => 0.9],
        ]);
        foreach ([[1, 2], [2, 3], [3, 4]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/universe/system_kills')->andReturn(new EsiResponse([
            ['system_id' => 2, 'npc_kills' => 300, 'ship_kills' => 0, 'pod_kills' => 0], // active constellation, quiet system
            ['system_id' => 3, 'npc_kills' => 400, 'ship_kills' => 5, 'pod_kills' => 2], // camped
        ], $expires));
        $esi->shouldReceive('get')->with('/universe/system_jumps')->andReturn(new EsiResponse([
            ['system_id' => 3, 'ship_jumps' => 200],
        ], $expires));
    }

    private function scorer(): TargetScorerService
    {
        return $this->app->make(TargetScorerService::class);
    }

    public function test_scores_only_the_chosen_region(): void
    {
        $scored = $this->scorer()->scoreRegion(1, originSystemId: 1);

        $names = $scored->pluck('name')->all();
        $this->assertContains('Quiet', $names);
        $this->assertNotContains('Elsewhere', $names); // other region
    }

    public function test_quiet_deadend_in_active_constellation_wins_over_camped(): void
    {
        $scored = $this->scorer()->scoreRegion(1, originSystemId: 1);

        $quiet = $scored->firstWhere('name', 'Quiet');
        $camped = $scored->firstWhere('name', 'Camped');
        $deadEnd = $scored->firstWhere('name', 'DeadEnd');

        // All share the constellation's spawn evidence.
        $this->assertGreaterThan(0, $quiet->constellationNpcKills);
        // Camped loses on its own NPC kills, traffic and live danger.
        $this->assertGreaterThan($camped->score, $quiet->score);
        $this->assertSame(7, $camped->liveDanger); // 5 ship + 2 pod kills

        // DeadEnd (1 gate, zero kills) gets the conditional dead-end bonus.
        $this->assertTrue($deadEnd->deadEnd);
        $this->assertGreaterThan($camped->score, $deadEnd->score);
    }

    public function test_faction_filter_excludes_wrong_region(): void
    {
        // Fountain is Serpentis space -> Guristas filter yields nothing.
        $this->assertTrue($this->scorer()->scoreRegion(1, faction: 'Guristas')->isEmpty());
        $this->assertTrue($this->scorer()->scoreRegion(1, faction: 'Serpentis')->isNotEmpty());
    }

    public function test_security_band_filter(): void
    {
        $this->assertTrue($this->scorer()->scoreRegion(1, securityBand: 'highsec')->isEmpty());
        $this->assertTrue($this->scorer()->scoreRegion(1, securityBand: 'nullsec')->isNotEmpty());
    }

    public function test_own_logged_sites_raise_the_score(): void
    {
        $character = Character::factory()->create();
        $without = $this->scorer()->scoreRegion(1, $character, originSystemId: 1)->firstWhere('name', 'Quiet');

        DB::table('signatures')->insert([
            'character_id' => $character->character_id, 'system_id' => 2,
            'sig_id' => 'AAA-111', 'sig_group' => 'Cosmic Anomaly', 'category' => 'Combat Site',
            'status' => 'active', 'first_seen' => now()->subDay(), 'last_seen' => now()->subDay(),
        ]);

        $with = $this->scorer()->scoreRegion(1, $character, originSystemId: 1)->firstWhere('name', 'Quiet');

        $this->assertSame(1, $with->ownSites);
        $this->assertGreaterThan($without->score, $with->score);
    }
}
