<?php

namespace Tests\Feature\Agents;

use App\Models\Character;
use App\Services\Agents\AgentFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentFinderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('solar_systems')->insert([
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Here', 'security' => 0.9],
            ['system_id' => 2, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Next', 'security' => 0.7],
        ]);
        DB::table('system_jumps')->insert([
            ['from_system_id' => 1, 'to_system_id' => 2],
            ['from_system_id' => 2, 'to_system_id' => 1],
        ]);
        DB::table('stations')->insert([
            ['station_id' => 60000001, 'system_id' => 2, 'name' => 'Next IV - Navy', 'corporation_id' => 1000120],
        ]);
        DB::table('npc_corporations')->insert([
            ['corporation_id' => 1000120, 'name' => 'Caldari Navy', 'faction_id' => 500001],
        ]);
        DB::table('factions')->insert([['faction_id' => 500001, 'name' => 'Caldari State']]);
        DB::table('npc_divisions')->insert([['division_id' => 24, 'name' => 'Security']]);
        DB::table('agents')->insert([
            ['agent_id' => 3001, 'division_id' => 24, 'corporation_id' => 1000120, 'location_id' => 60000001,
                'level' => 4, 'agent_type_id' => 2, 'is_locator' => false],
            // Storyline agents never appear.
            ['agent_id' => 3002, 'division_id' => 24, 'corporation_id' => 1000120, 'location_id' => 60000001,
                'level' => 4, 'agent_type_id' => 7, 'is_locator' => false],
        ]);
    }

    public function test_agent_availability_uses_connections_boosted_best_standing(): void
    {
        $character = Character::factory()->create();

        // Corp standing 4.0 + Connections IV: 4 + 6*0.16 = 4.96 -> NOT enough for L4.
        DB::table('character_standings')->insert([
            ['character_id' => $character->character_id, 'from_id' => 1000120, 'from_type' => 'npc_corp', 'standing' => 4.0],
        ]);
        DB::table('item_types')->insert([
            ['type_id' => 3359, 'group_id' => 1, 'name' => 'Connections', 'published' => true],
        ]);
        DB::table('character_skills')->insert([
            ['character_id' => $character->character_id, 'skill_id' => 3359, 'trained_level' => 4, 'active_level' => 4, 'skillpoints' => 0],
        ]);

        $result = $this->app->make(AgentFinderService::class)->nearby($character, 1, minLevel: 4);

        $this->assertCount(1, $result->agents); // the storyline agent is filtered out
        $agent = $result->agents->first();
        $this->assertSame('Caldari Navy', $agent->corp);
        $this->assertSame(1, $agent->distance);
        $this->assertEqualsWithDelta(4.96, $agent->standing, 0.01);
        $this->assertFalse($agent->available);

        // Faction standing 5.5 makes it available (highest of the three wins).
        DB::table('character_standings')->insert([
            ['character_id' => $character->character_id, 'from_id' => 500001, 'from_type' => 'faction', 'standing' => 5.5],
        ]);

        $agent = $this->app->make(AgentFinderService::class)->nearby($character, 1, minLevel: 4)->agents->first();
        $this->assertTrue($agent->available);
    }

    public function test_routing_safety_excludes_unreachable_agents(): void
    {
        DB::table('solar_systems')->where('system_id', 2)->update(['security' => 0.2]);

        $character = Character::factory()->create(['route_security' => 'highsec']);

        $result = $this->app->make(AgentFinderService::class)->nearby($character, 1, minLevel: 1);

        $this->assertTrue($result->agents->isEmpty());
    }
}
