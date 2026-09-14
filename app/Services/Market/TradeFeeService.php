<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Facades\DB;

/**
 * Trade fee rates from the character's actual skills and standings.
 * Sales tax: 7.5% reduced 11%/level by Accounting (3.375% at V).
 * Broker fee (NPC station): 3% − 0.3%/level of Broker Relations
 * − 0.03%/point of faction standing − 0.02%/point of corp standing with the
 * station owner (Connections-adjusted, floor 1%). Without a station (or
 * before the standings scope is granted) only the skill part applies.
 */
class TradeFeeService
{
    private const SALES_TAX_BASE = 0.075;

    private const BROKER_FEE_BASE = 0.03;

    /** Connections: +4%/level of the gap to 10 on positive NPC standings. */
    private const CONNECTIONS_SKILL_NAME = 'Connections';

    public function __construct(
        private readonly int $accountingSkillId,
        private readonly int $brokerRelationsSkillId,
    ) {}

    public function salesTaxRate(Character $character): float
    {
        return self::SALES_TAX_BASE * (1 - 0.11 * $this->skillLevel($character, $this->accountingSkillId));
    }

    public function brokerFeeRate(Character $character, ?int $stationId = null): float
    {
        $fee = self::BROKER_FEE_BASE - 0.003 * $this->skillLevel($character, $this->brokerRelationsSkillId);

        if ($stationId !== null) {
            [$factionStanding, $corpStanding] = $this->stationStandings($character, $stationId);
            $fee -= 0.0003 * $factionStanding + 0.0002 * $corpStanding;
        }

        return max(0.01, $fee);
    }

    public function skillLevel(Character $character, int $skillId): int
    {
        return (int) DB::table('character_skills')
            ->where('character_id', $character->character_id)
            ->where('skill_id', $skillId)
            ->value('trained_level');
    }

    /**
     * Connections-adjusted faction and owner-corp standings toward the
     * station's owner, zero-floored (negative standings raise nothing here —
     * the fee formula only credits positive standing).
     *
     * @return array{0: float, 1: float}
     */
    private function stationStandings(Character $character, int $stationId): array
    {
        $owner = DB::table('stations as s')
            ->leftJoin('npc_corporations as c', 'c.corporation_id', '=', 's.corporation_id')
            ->where('s.station_id', $stationId)
            ->first(['s.corporation_id', 'c.faction_id']);

        if ($owner === null || $owner->corporation_id === null) {
            return [0.0, 0.0];
        }

        $standings = DB::table('character_standings')
            ->where('character_id', $character->character_id)
            ->whereIn('from_id', array_filter([(int) $owner->corporation_id, (int) ($owner->faction_id ?? 0)]))
            ->pluck('standing', 'from_id');

        $connections = $this->connectionsLevel($character);

        $effective = function (?float $base) use ($connections): float {
            if ($base === null || $base <= 0) {
                return 0.0;
            }

            return $base + (10 - $base) * 0.04 * $connections;
        };

        return [
            $effective($owner->faction_id !== null ? (float) ($standings[(int) $owner->faction_id] ?? 0) : null),
            $effective((float) ($standings[(int) $owner->corporation_id] ?? 0)),
        ];
    }

    private function connectionsLevel(Character $character): int
    {
        return (int) DB::table('character_skills as cs')
            ->join('item_types as it', 'it.type_id', '=', 'cs.skill_id')
            ->where('cs.character_id', $character->character_id)
            ->where('it.name', self::CONNECTIONS_SKILL_NAME)
            ->value('cs.active_level');
    }
}
