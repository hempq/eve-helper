<?php

namespace Tests\Feature\Characters;

use App\Models\Character;
use App\Services\Characters\WalletAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_journal_into_income_and_spending_buckets(): void
    {
        $character = Character::factory()->create();

        DB::table('wallet_journal')->insert([
            ['journal_id' => 1, 'character_id' => $character->character_id, 'ref_type' => 'bounty_prizes',
                'amount' => 5_000_000, 'balance' => 0, 'date' => now()->subDays(2), 'context_id' => null, 'context_id_type' => null, 'description' => ''],
            ['journal_id' => 2, 'character_id' => $character->character_id, 'ref_type' => 'bounty_prizes',
                'amount' => 3_000_000, 'balance' => 0, 'date' => now()->subDays(1), 'context_id' => null, 'context_id_type' => null, 'description' => ''],
            ['journal_id' => 3, 'character_id' => $character->character_id, 'ref_type' => 'market_transaction',
                'amount' => 2_000_000, 'balance' => 0, 'date' => now()->subDays(3), 'context_id' => null, 'context_id_type' => null, 'description' => ''],
            ['journal_id' => 4, 'character_id' => $character->character_id, 'ref_type' => 'brokers_fee',
                'amount' => -100_000, 'balance' => 0, 'date' => now()->subDays(3), 'context_id' => null, 'context_id_type' => null, 'description' => ''],
            // Outside the window — ignored.
            ['journal_id' => 5, 'character_id' => $character->character_id, 'ref_type' => 'bounty_prizes',
                'amount' => 99_000_000, 'balance' => 0, 'date' => now()->subDays(60), 'context_id' => null, 'context_id_type' => null, 'description' => ''],
        ]);

        $breakdown = $this->app->make(WalletAnalyticsService::class)->breakdown($character, 30);

        $this->assertSame('Bounties (ratting)', $breakdown->income->first()->bucket);
        $this->assertEqualsWithDelta(8_000_000, $breakdown->income->first()->amount, 0.1);
        $this->assertSame('Trade taxes & fees', $breakdown->spending->first()->bucket);
        $this->assertEqualsWithDelta(100_000, $breakdown->spending->first()->amount, 0.1);
        $this->assertEqualsWithDelta(10_000_000, $breakdown->totalIncome, 0.1);
        $this->assertEqualsWithDelta(9_900_000, $breakdown->net, 0.1);
    }
}
