<?php

namespace App\Services\Characters;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pulls the character's skills, skill queue and attributes from ESI into the
 * local database. Safe to call often: the ESI client already honors cache
 * timers, and a staleness guard skips full re-syncs done moments ago.
 */
class CharacterSyncService
{
    private const STALE_AFTER_MINUTES = 5;

    public function __construct(private readonly EsiClientInterface $esi) {}

    public function sync(Character $character, bool $force = false): void
    {
        if (! $force && $character->last_synced_at?->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return;
        }

        $this->syncSkills($character);
        $this->syncSkillQueue($character);
        $this->syncAttributes($character);

        $character->forceFill(['last_synced_at' => CarbonImmutable::now()])->save();
    }

    private function syncSkills(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/skills", [], $character);

        $rows = array_map(fn (array $skill) => [
            'character_id' => $character->character_id,
            'skill_id' => $skill['skill_id'],
            'trained_level' => $skill['trained_skill_level'],
            'active_level' => $skill['active_skill_level'],
            'skillpoints' => $skill['skillpoints_in_skill'],
        ], $response->data['skills']);

        DB::transaction(function () use ($character, $rows, $response) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_skills')->upsert($chunk, ['character_id', 'skill_id']);
            }

            $character->forceFill([
                'total_sp' => $response->data['total_sp'] ?? null,
                'unallocated_sp' => $response->data['unallocated_sp'] ?? 0,
            ])->save();
        });
    }

    private function syncSkillQueue(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/skillqueue", [], $character);

        $rows = array_map(fn (array $entry) => [
            'character_id' => $character->character_id,
            'position' => $entry['queue_position'],
            'skill_id' => $entry['skill_id'],
            'finished_level' => $entry['finished_level'],
            // Missing dates mean the queue is paused.
            'start_date' => isset($entry['start_date']) ? CarbonImmutable::parse($entry['start_date']) : null,
            'finish_date' => isset($entry['finish_date']) ? CarbonImmutable::parse($entry['finish_date']) : null,
            'level_start_sp' => $entry['level_start_sp'] ?? null,
            'level_end_sp' => $entry['level_end_sp'] ?? null,
            'training_start_sp' => $entry['training_start_sp'] ?? null,
        ], $response->data);

        // Positions shift on every queue change; replace wholesale.
        DB::transaction(function () use ($character, $rows) {
            DB::table('character_skill_queue')->where('character_id', $character->character_id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_skill_queue')->insert($chunk);
            }
        });
    }

    private function syncAttributes(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/attributes", [], $character);
        $data = $response->data;

        $character->forceFill([
            'charisma' => $data['charisma'],
            'intelligence' => $data['intelligence'],
            'memory' => $data['memory'],
            'perception' => $data['perception'],
            'willpower' => $data['willpower'],
            'bonus_remaps' => $data['bonus_remaps'] ?? null,
            'last_remap_date' => isset($data['last_remap_date']) ? CarbonImmutable::parse($data['last_remap_date']) : null,
            'accrued_remap_cooldown_date' => isset($data['accrued_remap_cooldown_date']) ? CarbonImmutable::parse($data['accrued_remap_cooldown_date']) : null,
        ])->save();
    }
}
