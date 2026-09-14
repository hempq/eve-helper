<?php

namespace Tests\Feature\Characters;

use App\Models\Character;
use App\Services\Characters\CharacterSyncService;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class CharacterSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEsi(Character $character): MockInterface
    {
        $esi = $this->mock(EsiClientInterface::class);
        $expires = CarbonImmutable::now()->addMinutes(5);
        $id = $character->character_id;

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/skills", [], $character)
            ->andReturn(new EsiResponse([
                'total_sp' => 3_520_751,
                'unallocated_sp' => 1_500,
                'skills' => [
                    ['skill_id' => 3300, 'trained_skill_level' => 5, 'active_skill_level' => 5, 'skillpoints_in_skill' => 256000],
                    ['skill_id' => 3402, 'trained_skill_level' => 3, 'active_skill_level' => 2, 'skillpoints_in_skill' => 8000],
                ],
            ], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/skillqueue", [], $character)
            ->andReturn(new EsiResponse([
                [
                    'queue_position' => 0,
                    'skill_id' => 3332,
                    'finished_level' => 4,
                    'start_date' => '2026-09-14T06:37:28Z',
                    'finish_date' => '2026-09-16T12:04:39Z',
                    'level_start_sp' => 40000,
                    'level_end_sp' => 226275,
                    'training_start_sp' => 110816,
                ],
                [
                    'queue_position' => 1,
                    'skill_id' => 3327,
                    'finished_level' => 5,
                    // paused entries carry no dates
                ],
            ], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/implants", [], $character)
            ->andReturn(new EsiResponse([10216, 10217], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/clones", [], $character)
            ->andReturn(new EsiResponse([
                'last_clone_jump_date' => '2026-09-10T08:00:00Z',
                'jump_clones' => [
                    ['jump_clone_id' => 1, 'name' => 'Hisec learning', 'location_id' => 60003760,
                        'location_type' => 'station', 'implants' => [10216, 10217]],
                ],
            ], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/wallet", [], $character)
            ->andReturn(new EsiResponse([12_345_678.90], $expires));

        $esi->shouldReceive('getAllPages')
            ->with("/characters/{$id}/wallet/journal", [], $character)
            ->andReturn([
                ['id' => 555001, 'ref_type' => 'bounty_prizes', 'amount' => 1_500_000.5, 'balance' => 20_000_000.0,
                    'date' => '2026-09-14T10:00:00Z', 'context_id' => 30000142, 'context_id_type' => 'system_id',
                    'description' => 'Bounty prizes'],
            ]);

        $esi->shouldReceive('getAllPages')
            ->with("/characters/{$id}/contracts", [], $character)
            ->andReturn([
                ['contract_id' => 88001, 'type' => 'item_exchange', 'status' => 'outstanding',
                    'title' => 'Gistii B-Type', 'price' => 250_000_000, 'reward' => 0, 'collateral' => 0,
                    'for_corporation' => false, 'date_issued' => '2026-09-13T10:00:00Z',
                    'date_expired' => '2026-09-27T10:00:00Z'],
            ]);

        $esi->shouldReceive('getAllPages')
            ->with("/characters/{$id}/assets", [], $character)
            ->andReturn([
                ['item_id' => 9001, 'type_id' => 587, 'quantity' => 1, 'location_id' => 60003760,
                    'location_flag' => 'Hangar', 'location_type' => 'station', 'is_singleton' => true],
                ['item_id' => 9002, 'type_id' => 34, 'quantity' => 2500, 'location_id' => 60003760,
                    'location_flag' => 'Hangar', 'location_type' => 'station', 'is_singleton' => false],
            ]);

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/orders", [], $character)
            ->andReturn(new EsiResponse([
                ['order_id' => 7001, 'type_id' => 34, 'location_id' => 60003760, 'region_id' => 10000002,
                    'is_buy_order' => false, 'price' => 5.5, 'volume_remain' => 100, 'volume_total' => 500,
                    'issued' => '2026-09-13T12:00:00Z'],
            ], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/wallet/transactions", [], $character)
            ->andReturn(new EsiResponse([
                ['transaction_id' => 777001, 'date' => '2026-09-14T09:00:00Z', 'type_id' => 34,
                    'quantity' => 1000, 'unit_price' => 5.5, 'is_buy' => false, 'location_id' => 60003760,
                    'client_id' => 1, 'journal_ref_id' => 555002],
            ], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/standings", [], $character)
            ->andReturn(new EsiResponse([
                ['from_id' => 500001, 'from_type' => 'faction', 'standing' => 2.5],
                ['from_id' => 1000035, 'from_type' => 'npc_corp', 'standing' => 4.1],
            ], $expires));

        $esi->shouldReceive('get')
            ->with("/characters/{$id}/attributes", [], $character)
            ->andReturn(new EsiResponse([
                'charisma' => 23,
                'intelligence' => 24,
                'memory' => 24,
                'perception' => 24,
                'willpower' => 24,
                'bonus_remaps' => 2,
            ], $expires));

        return $esi;
    }

    public function test_sync_stores_skills_queue_and_attributes(): void
    {
        $character = Character::factory()->create();
        $this->fakeEsi($character);

        $this->app->make(CharacterSyncService::class)->sync($character);
        $character->refresh();

        $this->assertSame(3_520_751, (int) $character->total_sp);
        $this->assertSame(1_500, (int) $character->unallocated_sp);
        $this->assertSame(24, (int) $character->perception);
        $this->assertSame(23, (int) $character->charisma);
        $this->assertSame(2, (int) $character->bonus_remaps);
        $this->assertNotNull($character->last_synced_at);

        $this->assertSame(2, $character->skills()->count());
        $gunnery = $character->skills()->where('skill_id', 3300)->first();
        $this->assertSame(5, (int) $gunnery->trained_level);
        $this->assertSame(256000, (int) $gunnery->skillpoints);

        $queue = $character->skillQueue;
        $this->assertCount(2, $queue);
        $this->assertSame(3332, (int) $queue[0]->skill_id);
        $this->assertSame('2026-09-16 12:04:39', $queue[0]->finish_date->toDateTimeString());
        $this->assertNull($queue[1]->start_date);

        $implants = DB::table('character_implants')
            ->where('character_id', $character->character_id)->pluck('type_id')->all();
        $this->assertEqualsCanonicalizing([10216, 10217], array_map(intval(...), $implants));

        $this->assertEqualsWithDelta(12_345_678.90, (float) $character->wallet_balance, 0.01);

        $clone = DB::table('character_clones')->where('character_id', $character->character_id)->first();
        $this->assertSame('Hisec learning', $clone->name);
        $this->assertSame([10216, 10217], json_decode($clone->implants, true));
        $this->assertSame('2026-09-10 08:00:00', $character->last_clone_jump_date->toDateTimeString());

        $assets = DB::table('character_assets')->where('character_id', $character->character_id)->get();
        $this->assertCount(2, $assets);
        $this->assertSame(2500, (int) $assets->firstWhere('type_id', 34)->quantity);

        $journal = DB::table('wallet_journal')->where('journal_id', 555001)->first();
        $this->assertSame('bounty_prizes', $journal->ref_type);
        $this->assertEqualsWithDelta(1_500_000.5, (float) $journal->amount, 0.01);

        $order = DB::table('character_orders')->where('order_id', 7001)->first();
        $this->assertSame(34, (int) $order->type_id);
        $this->assertEqualsWithDelta(5.5, (float) $order->price, 0.001);

        $contract = DB::table('character_contracts')->where('contract_id', 88001)->first();
        $this->assertSame('outstanding', $contract->status);
        $this->assertEqualsWithDelta(250_000_000, (float) $contract->price, 0.01);
    }

    public function test_queue_is_replaced_not_appended_on_resync(): void
    {
        $character = Character::factory()->create();
        DB::table('character_skill_queue')->insert([
            'character_id' => $character->character_id,
            'position' => 0,
            'skill_id' => 999,
            'finished_level' => 5,
        ]);

        $this->fakeEsi($character);
        $this->app->make(CharacterSyncService::class)->sync($character, force: true);

        $this->assertSame(2, $character->skillQueue()->count());
        $this->assertSame(0, $character->skillQueue()->where('skill_id', 999)->count());
    }

    public function test_recent_sync_is_skipped_unless_forced(): void
    {
        $character = Character::factory()->create(['last_synced_at' => now()->subMinute()]);

        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldNotReceive('get');

        $this->app->make(CharacterSyncService::class)->sync($character);
    }
}
