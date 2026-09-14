<?php

namespace Tests\Feature\Skills;

use App\Models\Character;
use App\Services\Skills\QueueAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_report_from_queue_with_implant_bonuses(): void
    {
        $character = Character::factory()->create([
            'charisma' => 19, 'intelligence' => 20, 'memory' => 20,
            'perception' => 25, 'willpower' => 20, // includes +5 perception implant
            'bonus_remaps' => 2,
            'last_remap_date' => null,
        ]);

        DB::table('skill_types')->insert([
            ['type_id' => 3332, 'rank' => 2, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
            ['type_id' => 3402, 'rank' => 1, 'primary_attribute' => 'intelligence', 'secondary_attribute' => 'memory'],
        ]);

        DB::table('character_skill_queue')->insert([
            [
                'character_id' => $character->character_id, 'position' => 0, 'skill_id' => 3332,
                'finished_level' => 4, 'level_start_sp' => 40000, 'level_end_sp' => 226275,
                'training_start_sp' => 110816, 'start_date' => now(), 'finish_date' => now()->addDays(2),
            ],
            [
                'character_id' => $character->character_id, 'position' => 1, 'skill_id' => 3402,
                'finished_level' => 5, 'level_start_sp' => 45255, 'level_end_sp' => 256000,
                'training_start_sp' => null, 'start_date' => null, 'finish_date' => null,
            ],
        ]);

        DB::table('implant_bonuses')->insert([
            ['type_id' => 10216, 'attribute' => 'perception', 'bonus' => 5],
        ]);
        DB::table('character_implants')->insert([
            ['character_id' => $character->character_id, 'type_id' => 10216],
        ]);

        $report = $this->app->make(QueueAnalysisService::class)->remapReport($character);

        $this->assertNotNull($report);

        // SP: active entry counts from training_start_sp (226275-110816),
        // queued entry from level_start_sp (256000-45255).
        $this->assertSame((226275 - 110816) + (256000 - 45255), $report->totalSp);
        $this->assertSame(2, $report->skillCount);
        $this->assertCount(2, $report->buckets);

        // Implant separation: base = current - bonuses.
        $this->assertSame(5, $report->implantBonuses->perception);
        $this->assertSame(20, $report->currentBase->perception);
        $this->assertSame(99, $report->currentBase->total());

        // Optimal must not be slower than current.
        $this->assertLessThanOrEqual($report->currentMinutes, $report->optimalMinutes);
        $this->assertSame(99, $report->optimalBase->total());

        // 2 bonus remaps, never remapped -> can remap now.
        $this->assertTrue($report->canRemapNow());
        $this->assertNull($report->nextYearlyRemapAt);
    }

    public function test_returns_null_for_empty_queue(): void
    {
        $character = Character::factory()->create();

        $this->assertNull($this->app->make(QueueAnalysisService::class)->remapReport($character));
    }

    public function test_yearly_remap_cooldown_is_respected(): void
    {
        $character = Character::factory()->create([
            'charisma' => 19, 'intelligence' => 20, 'memory' => 20,
            'perception' => 20, 'willpower' => 20,
            'bonus_remaps' => 0,
            'last_remap_date' => now()->subMonths(3),
        ]);

        DB::table('skill_types')->insert([
            ['type_id' => 3332, 'rank' => 2, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
        ]);
        DB::table('character_skill_queue')->insert([
            [
                'character_id' => $character->character_id, 'position' => 0, 'skill_id' => 3332,
                'finished_level' => 4, 'level_start_sp' => 40000, 'level_end_sp' => 226275,
            ],
        ]);

        $report = $this->app->make(QueueAnalysisService::class)->remapReport($character);

        $this->assertFalse($report->canRemapNow());
        $this->assertTrue($report->nextYearlyRemapAt->isFuture());
    }
}
