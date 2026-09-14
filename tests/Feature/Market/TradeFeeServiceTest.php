<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\TradeFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TradeFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedSkill(Character $character, int $skillId, int $level): void
    {
        DB::table('character_skills')->insert([
            'character_id' => $character->character_id, 'skill_id' => $skillId,
            'trained_level' => $level, 'active_level' => $level, 'skillpoints' => 1,
        ]);
    }

    public function test_rates_without_trade_skills(): void
    {
        $character = Character::factory()->create();
        $fees = $this->app->make(TradeFeeService::class);

        $this->assertEqualsWithDelta(0.075, $fees->salesTaxRate($character), 1e-9);
        $this->assertEqualsWithDelta(0.03, $fees->brokerFeeRate($character), 1e-9);
    }

    public function test_rates_with_maxed_trade_skills(): void
    {
        $character = Character::factory()->create();
        $this->seedSkill($character, config('eve.market.accounting_skill_id'), 5);
        $this->seedSkill($character, config('eve.market.broker_relations_skill_id'), 5);

        $fees = $this->app->make(TradeFeeService::class);

        // 7.5% * (1 - 0.55) = 3.375%; 3% - 1.5% = 1.5%.
        $this->assertEqualsWithDelta(0.03375, $fees->salesTaxRate($character), 1e-9);
        $this->assertEqualsWithDelta(0.015, $fees->brokerFeeRate($character), 1e-9);
    }
}
