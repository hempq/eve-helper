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
