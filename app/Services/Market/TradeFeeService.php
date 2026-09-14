<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Facades\DB;

/**
 * Trade fee rates from the character's actual skills.
 * Sales tax: 7.5% reduced 11%/level by Accounting (3.375% at V).
 * Broker fee (NPC station): 3% − 0.3%/level of Broker Relations, floor 1%;
 * faction/corp standing bonuses are ignored for now (small, and standings
 * are not synced yet).
 */
class TradeFeeService
{
    private const SALES_TAX_BASE = 0.075;

    private const BROKER_FEE_BASE = 0.03;

    public function __construct(
        private readonly int $accountingSkillId,
        private readonly int $brokerRelationsSkillId,
    ) {}

    public function salesTaxRate(Character $character): float
    {
        return self::SALES_TAX_BASE * (1 - 0.11 * $this->skillLevel($character, $this->accountingSkillId));
    }

    public function brokerFeeRate(Character $character): float
    {
        return max(0.01, self::BROKER_FEE_BASE - 0.003 * $this->skillLevel($character, $this->brokerRelationsSkillId));
    }

    public function skillLevel(Character $character, int $skillId): int
    {
        return (int) DB::table('character_skills')
            ->where('character_id', $character->character_id)
            ->where('skill_id', $skillId)
            ->value('trained_level');
    }
}
