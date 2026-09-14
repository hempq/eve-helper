<?php

namespace Tests\Feature\Industry;

use App\Models\Character;
use App\Services\Agents\ResearchAgentService;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Industry\IndustryService;
use App\Services\Industry\MiningLedgerService;
use App\Services\Industry\PlanetaryService;
use App\Services\Market\FittingCostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IndustryFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function expires(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHour();
    }

    public function test_research_agents_accrue_datacores(): void
    {
        CarbonImmutable::setTestNow('2026-09-14 12:00:00');
        $character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 11453, 'group_id' => 1, 'name' => 'Mechanical Engineering', 'published' => true],
            ['type_id' => 20424, 'group_id' => 2, 'name' => 'Datacore - Mechanical Engineering', 'published' => true],
        ]);

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/agents_research", [], $character)
            ->andReturn(new EsiResponse([
                // 50 RP remainder + 25 RP/day × 10 days = 300 RP -> 3 datacores.
                ['agent_id' => 3001, 'skill_type_id' => 11453, 'started_at' => '2026-09-04T12:00:00Z',
                    'points_per_day' => 25.0, 'remainder_points' => 50.0],
            ], $this->expires()));

        Http::fake(['market.fuzzwork.co.uk/*' => Http::response([
            '20424' => ['buy' => ['percentile' => 80_000], 'sell' => ['percentile' => 100_000]],
        ])]);

        $summary = $this->app->make(ResearchAgentService::class)->summary($character);

        $agent = $summary->agents->first();
        $this->assertSame('Mechanical Engineering', $agent->science);
        $this->assertSame(3, $agent->datacores);
        $this->assertEqualsWithDelta(240_000, $agent->value, 0.1);
        $this->assertEqualsWithDelta(20_000, $agent->iskPerDay, 0.1); // 25/100 × 80k
    }

    public function test_planetary_colonies_report_extractor_expiry(): void
    {
        $character = Character::factory()->create();
        DB::table('solar_systems')->insert([
            ['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9],
        ]);

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/planets", [], $character)
            ->andReturn(new EsiResponse([
                ['planet_id' => 40001, 'planet_type' => 'barren', 'solar_system_id' => 30000142,
                    'num_pins' => 9, 'upgrade_level' => 4, 'owner_id' => $character->character_id, 'last_update' => '2026-09-14T00:00:00Z'],
            ], $this->expires()));
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/planets/40001", [], $character)
            ->andReturn(new EsiResponse([
                'pins' => [
                    ['pin_id' => 1, 'type_id' => 2848, 'expiry_time' => now()->subHour()->toIso8601String(),
                        'extractor_details' => ['cycle_time' => 3600]],
                    ['pin_id' => 2, 'type_id' => 2544], // factory, no expiry
                ],
            ], $this->expires()));

        $result = $this->app->make(PlanetaryService::class)->colonies($character);

        $colony = $result->colonies->first();
        $this->assertSame('Jita', $colony->system);
        $this->assertTrue($colony->expired);
    }

    public function test_mining_ledger_prices_ores(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            ['type_id' => 1230, 'group_id' => 1, 'name' => 'Veldspar', 'published' => true],
        ]);

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('getAllPages')
            ->with("/characters/{$character->character_id}/mining", [], $character)
            ->andReturn([
                ['date' => now()->subDays(2)->toDateString(), 'solar_system_id' => 1, 'type_id' => 1230, 'quantity' => 10_000],
                ['date' => now()->subDays(45)->toDateString(), 'solar_system_id' => 1, 'type_id' => 1230, 'quantity' => 99_999],
            ]);

        Http::fake(['market.fuzzwork.co.uk/*' => Http::response([
            '1230' => ['buy' => ['percentile' => 10.0], 'sell' => ['percentile' => 12.0]],
        ])]);

        $summary = $this->app->make(MiningLedgerService::class)->summary($character);

        $this->assertEqualsWithDelta(100_000, $summary->totalValue, 0.1); // only the in-window row
        $this->assertSame('Veldspar', $summary->ores->first()->name);
    }

    public function test_industry_jobs_flag_ready_and_blueprints_aggregate(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            ['type_id' => 587, 'group_id' => 1, 'name' => 'Rifter', 'published' => true],
            ['type_id' => 689, 'group_id' => 1, 'name' => 'Rifter Blueprint', 'published' => true],
        ]);

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/industry/jobs", [], $character)
            ->andReturn(new EsiResponse([
                ['job_id' => 1, 'activity_id' => 1, 'status' => 'active', 'runs' => 10,
                    'product_type_id' => 587, 'blueprint_type_id' => 689, 'end_date' => now()->subHour()->toIso8601String()],
                ['job_id' => 2, 'activity_id' => 4, 'status' => 'active', 'runs' => 1,
                    'product_type_id' => 689, 'blueprint_type_id' => 689, 'end_date' => now()->addDay()->toIso8601String()],
            ], $this->expires()));
        $esi->shouldReceive('getAllPages')
            ->with("/characters/{$character->character_id}/blueprints", [], $character)
            ->andReturn([
                ['item_id' => 1, 'type_id' => 689, 'material_efficiency' => 10, 'time_efficiency' => 20, 'quantity' => -1, 'runs' => -1],
                ['item_id' => 2, 'type_id' => 689, 'material_efficiency' => 5, 'time_efficiency' => 10, 'quantity' => -2, 'runs' => 30],
                ['item_id' => 3, 'type_id' => 689, 'material_efficiency' => 5, 'time_efficiency' => 10, 'quantity' => -2, 'runs' => 20],
            ]);

        $service = $this->app->make(IndustryService::class);

        $jobs = $service->jobs($character);
        $this->assertSame(1, $jobs->readyCount);
        $this->assertTrue($jobs->jobs->first()->ready); // ordered by end date

        $blueprints = $service->blueprints($character);
        $this->assertSame(1, $blueprints->originals);
        $this->assertSame(2, $blueprints->copies);
        $copy = $blueprints->blueprints->firstWhere('isCopy', true);
        $this->assertSame(50, $copy->runs);
    }

    public function test_fitting_costs_priced_at_jita_sell(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            ['type_id' => 587, 'group_id' => 1, 'name' => 'Rifter', 'published' => true],
        ]);

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/fittings", [], $character)
            ->andReturn(new EsiResponse([
                ['fitting_id' => 1, 'name' => 'Cheap Rifter', 'ship_type_id' => 587, 'items' => [
                    ['type_id' => 2046, 'quantity' => 1, 'flag' => 'LoSlot0'],
                    ['type_id' => 99999, 'quantity' => 1, 'flag' => 'HiSlot0'], // unpriced
                ]],
            ], $this->expires()));

        Http::fake(['market.fuzzwork.co.uk/*' => Http::response([
            '587' => ['buy' => ['percentile' => 400_000], 'sell' => ['percentile' => 500_000]],
            '2046' => ['buy' => ['percentile' => 80_000], 'sell' => ['percentile' => 100_000]],
        ])]);

        $result = $this->app->make(FittingCostService::class)->replacementCosts($character);

        $fit = $result->fittings->first();
        $this->assertEqualsWithDelta(500_000, $fit->hullCost, 0.1);
        $this->assertEqualsWithDelta(100_000, $fit->fittingsCost, 0.1);
        $this->assertSame(1, $fit->unpriced);
    }

    public function test_expired_extractor_raises_alert(): void
    {
        $character = Character::factory()->create();
        DB::table('solar_systems')->insert([
            ['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9],
        ]);
        $this->mock(\App\Services\Market\UndercutService::class)->shouldReceive('check')->andReturn(collect());

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/planets", [], \Mockery::type(Character::class))
            ->andReturn(new EsiResponse([
                ['planet_id' => 40001, 'planet_type' => 'barren', 'solar_system_id' => 30000142,
                    'num_pins' => 9, 'upgrade_level' => 4, 'owner_id' => $character->character_id],
            ], $this->expires()));
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/planets/40001", [], \Mockery::type(Character::class))
            ->andReturn(new EsiResponse([
                'pins' => [['pin_id' => 1, 'expiry_time' => now()->subHour()->toIso8601String(),
                    'extractor_details' => ['cycle_time' => 3600]]],
            ], $this->expires()));
        // Every other optional-scope call (industry, notifications) is absent.
        $esi->shouldReceive('get')->andThrow(new EsiRequestFailed('403'));

        $this->app->make(\App\Services\Alerts\AlertService::class)->refresh($character);

        $alert = \App\Models\Alert::where('dedupe_key', 'pi_expired_40001')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('Jita', $alert->message);
    }

    public function test_missing_scopes_flag_needs_scope(): void
    {
        $character = Character::factory()->create();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->andThrow(new EsiRequestFailed('403'));
        $esi->shouldReceive('getAllPages')->andThrow(new EsiRequestFailed('403'));

        $this->assertTrue($this->app->make(PlanetaryService::class)->colonies($character)->needsScope);
        $this->assertTrue($this->app->make(IndustryService::class)->jobs($character)->needsScope);
        $this->assertTrue($this->app->make(IndustryService::class)->blueprints($character)->needsScope);
        $this->assertTrue($this->app->make(MiningLedgerService::class)->summary($character)->needsScope);
        $this->assertTrue($this->app->make(ResearchAgentService::class)->summary($character)->needsScope);
        $this->assertTrue($this->app->make(FittingCostService::class)->replacementCosts($character)->needsScope);
    }
}
