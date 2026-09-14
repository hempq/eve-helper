<?php

namespace Tests\Feature\Killmails;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Killmails\LossHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LossHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarizes_recent_losses_with_ship_and_system_names(): void
    {
        CarbonImmutable::setTestNow('2026-09-15 12:00:00');
        $character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 17843, 'group_id' => 1, 'name' => 'Vexor Navy Issue', 'published' => true],
            ['type_id' => 670, 'group_id' => 2, 'name' => 'Capsule', 'published' => true],
        ]);
        DB::table('solar_systems')->insert([
            ['system_id' => 30002053, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Hagilur', 'security' => 0.4],
        ]);

        Http::fake([
            "zkillboard.com/api/losses/characterID/{$character->character_id}/*" => Http::response([
                ['killmail_id' => 200, 'zkb' => ['hash' => 'aaa', 'totalValue' => 250_000_000.0, 'droppedValue' => 40_000_000.0, 'npc' => false, 'solo' => true]],
                ['killmail_id' => 100, 'zkb' => ['hash' => 'bbb', 'totalValue' => 10_000.0, 'droppedValue' => 0.0, 'npc' => true, 'solo' => false]],
            ]),
        ]);

        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/killmails/200/aaa/')
            ->andReturn(new EsiResponse([
                'killmail_time' => '2026-09-10T08:00:00Z',
                'solar_system_id' => 30002053,
                'victim' => ['ship_type_id' => 17843],
            ], $expires));
        $esi->shouldReceive('get')->with('/killmails/100/bbb/')
            ->andReturn(new EsiResponse([
                'killmail_time' => '2026-06-01T08:00:00Z', // older than 30 days
                'solar_system_id' => 30002053,
                'victim' => ['ship_type_id' => 670],
            ], $expires));

        $summary = $this->app->make(LossHistoryService::class)->summary($character);

        $this->assertTrue($summary->available);
        $this->assertCount(2, $summary->losses);

        $first = $summary->losses->first();
        $this->assertSame('Vexor Navy Issue', $first->shipName);
        $this->assertSame('Hagilur', $first->systemName);
        $this->assertEqualsWithDelta(0.4, $first->security, 0.001);
        $this->assertTrue($first->solo);

        $this->assertSame(1, $summary->count30d);
        $this->assertEqualsWithDelta(250_000_000.0, $summary->value30d, 0.1);
        $this->assertEqualsWithDelta(250_010_000.0, $summary->totalValue, 0.1);
        $this->assertFalse($summary->truncated);
    }

    public function test_unreachable_zkill_degrades_gracefully(): void
    {
        $character = Character::factory()->create();
        Http::fake(['zkillboard.com/*' => Http::response(null, 500)]);
        $this->mock(EsiClientInterface::class)->shouldReceive('get')->never();

        $summary = $this->app->make(LossHistoryService::class)->summary($character);

        $this->assertFalse($summary->available);
        $this->assertTrue($summary->losses->isEmpty());
    }

    public function test_no_losses_is_available_but_empty(): void
    {
        $character = Character::factory()->create();
        Http::fake(["zkillboard.com/api/losses/characterID/{$character->character_id}/*" => Http::response([])]);

        $summary = $this->app->make(LossHistoryService::class)->summary($character);

        $this->assertTrue($summary->available);
        $this->assertTrue($summary->losses->isEmpty());
        $this->assertSame(0.0, $summary->totalValue);
    }
}
