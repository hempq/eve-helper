<?php

namespace Tests\Feature\Farm;

use App\Models\Character;
use App\Services\Farm\RattingSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RattingSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticks_group_into_sessions_with_isk_per_hour(): void
    {
        $character = Character::factory()->create();

        DB::table('solar_systems')->insert([
            ['system_id' => 30004759, 'constellation_id' => 1, 'region_id' => 1, 'name' => '1DQ1-A', 'security' => -0.4],
        ]);

        $base = now()->subHours(5)->startOfHour();
        $journalId = 1;

        // Session 1: three ticks 20 minutes apart (40 min span + lead-in = 1h).
        foreach ([0, 20, 40] as $offset) {
            DB::table('wallet_journal')->insert([
                'journal_id' => $journalId++, 'character_id' => $character->character_id,
                'ref_type' => 'bounty_prizes', 'amount' => 10_000_000, 'date' => $base->copy()->addMinutes($offset),
                'context_id' => 30004759, 'context_id_type' => 'system_id',
            ]);
        }

        // 2-hour gap -> a separate single-tick session.
        DB::table('wallet_journal')->insert([
            'journal_id' => $journalId++, 'character_id' => $character->character_id,
            'ref_type' => 'bounty_prizes', 'amount' => 5_000_000, 'date' => $base->copy()->addMinutes(180),
            'context_id' => 30004759, 'context_id_type' => 'system_id',
        ]);

        // Noise: a market transaction must be ignored.
        DB::table('wallet_journal')->insert([
            'journal_id' => $journalId, 'character_id' => $character->character_id,
            'ref_type' => 'market_transaction', 'amount' => 999, 'date' => $base,
        ]);

        $sessions = $this->app->make(RattingSessionService::class)->sessions($character);

        $this->assertCount(2, $sessions);

        // Newest first: the lone tick.
        $this->assertSame(1, $sessions[0]->ticks);
        $this->assertEqualsWithDelta(5_000_000 / (20 / 60), $sessions[0]->iskPerHour, 1.0);

        $main = $sessions[1];
        $this->assertSame(3, $main->ticks);
        $this->assertSame(30_000_000.0, $main->isk);
        $this->assertSame(['1DQ1-A'], $main->systems);
        $this->assertEqualsWithDelta(1.0, $main->hours, 0.01); // 40 min span + 20 min lead-in
        $this->assertEqualsWithDelta(30_000_000, $main->iskPerHour, 1.0);
    }

    public function test_daily_totals(): void
    {
        $character = Character::factory()->create();

        DB::table('wallet_journal')->insert([
            ['journal_id' => 1, 'character_id' => $character->character_id, 'ref_type' => 'bounty_prizes',
                'amount' => 1_000_000, 'date' => now()->subDays(1)->setTime(20, 0)],
            ['journal_id' => 2, 'character_id' => $character->character_id, 'ref_type' => 'bounty_prizes',
                'amount' => 2_000_000, 'date' => now()->subDays(1)->setTime(21, 0)],
        ]);

        $totals = $this->app->make(RattingSessionService::class)->dailyTotals($character);

        $this->assertCount(1, $totals);
        $this->assertSame(3_000_000.0, $totals->first());
    }
}
