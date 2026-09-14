<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\TradeProfitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TradeProfitServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fill(Character $c, int $id, bool $isBuy, int $typeId, int $qty, float $price, int $daysAgo): void
    {
        DB::table('character_transactions')->insert([
            'transaction_id' => $id, 'character_id' => $c->character_id, 'date' => now()->subDays($daysAgo),
            'type_id' => $typeId, 'quantity' => $qty, 'unit_price' => $price, 'is_buy' => $isBuy, 'location_id' => 60003760,
        ]);
    }

    public function test_fifo_matches_sells_against_older_buys(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'published' => true],
        ]);

        // Bought 100 @ 4 (45 days ago, outside window) and 100 @ 5; sold 150 @ 6 within the window.
        $this->fill($character, 1, true, 34, 100, 4.0, 45);
        $this->fill($character, 2, true, 34, 100, 5.0, 10);
        $this->fill($character, 3, false, 34, 150, 6.0, 5);
        // A sell with no recorded buy at all -> loot revenue.
        $this->fill($character, 4, false, 587, 1, 1_000_000, 3);

        DB::table('wallet_journal')->insert([
            ['journal_id' => 1, 'character_id' => $character->character_id, 'ref_type' => 'transaction_tax',
                'amount' => -30, 'balance' => 0, 'date' => now()->subDays(5), 'context_id' => null, 'context_id_type' => null, 'description' => ''],
        ]);

        $result = $this->app->make(TradeProfitService::class)->realized($character, 30);

        $item = $result->items->firstWhere('typeId', 34);
        $this->assertSame(150, $item->sold);
        // FIFO cost: 100×4 + 50×5 = 650; revenue 150×6 = 900 -> profit 250.
        $this->assertEqualsWithDelta(650.0, $item->cost, 0.01);
        $this->assertEqualsWithDelta(250.0, $item->profit, 0.01);

        $this->assertEqualsWithDelta(250.0, $result->realizedProfit, 0.01);
        $this->assertEqualsWithDelta(1_000_000.0, $result->lootRevenue, 0.01);
        $this->assertEqualsWithDelta(30.0, $result->feesPaid, 0.01);
    }

    public function test_partially_matched_sell_splits_into_loot_share(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'published' => true],
        ]);

        // Only 40 of the 100 sold units have a recorded buy.
        $this->fill($character, 1, true, 34, 40, 5.0, 10);
        $this->fill($character, 2, false, 34, 100, 10.0, 5);

        $result = $this->app->make(TradeProfitService::class)->realized($character, 30);

        $item = $result->items->firstWhere('typeId', 34);
        $this->assertSame(40, $item->sold);
        $this->assertEqualsWithDelta(200.0, $item->profit, 0.01);   // 40×10 − 40×5
        $this->assertEqualsWithDelta(600.0, $result->lootRevenue, 0.01); // 60×10
    }
}
