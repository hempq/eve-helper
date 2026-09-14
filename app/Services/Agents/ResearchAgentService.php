<?php

namespace App\Services\Agents;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\PriceProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * R&D agent research points — the passive datacore income most players
 * forget for years. Points accrue continuously (remainder + rate × days
 * since start); one datacore costs 100 RP, valued at the Jita buy price.
 * Needs esi-characters.read_agents_research.v1 (re-login to grant).
 */
class ResearchAgentService
{
    private const JITA = 60003760;

    private const RP_PER_DATACORE = 100;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly PriceProviderInterface $prices,
        private readonly \App\Services\Market\TradeFeeService $fees,
    ) {}

    /**
     * @return object{needsScope: bool, agents: \Illuminate\Support\Collection<int, object>, totalValue: float}
     */
    public function summary(Character $character): object
    {
        try {
            $rows = $this->esi->get("/characters/{$character->character_id}/agents_research", [], $character)->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['needsScope' => true, 'agents' => collect(), 'totalValue' => 0.0];
        }

        if ($rows === []) {
            return (object) ['needsScope' => false, 'agents' => collect(), 'totalValue' => 0.0];
        }

        $agentIds = array_map(fn ($r) => (int) $r['agent_id'], $rows);
        $agentMeta = DB::table('agents as a')
            ->leftJoin('stations as st', 'st.station_id', '=', 'a.location_id')
            ->leftJoin('npc_corporations as c', 'c.corporation_id', '=', 'a.corporation_id')
            ->whereIn('a.agent_id', $agentIds)
            ->get(['a.agent_id', 'a.level', 'st.name as station', 'c.name as corp'])
            ->keyBy('agent_id');

        // The researched science skill names the datacore: skill "Mechanical
        // Engineering" -> item "Datacore - Mechanical Engineering".
        $skillNames = DB::table('item_types')
            ->whereIn('type_id', array_map(fn ($r) => (int) $r['skill_type_id'], $rows))
            ->pluck('name', 'type_id');
        $datacoreTypes = DB::table('item_types')
            ->whereIn('name', $skillNames->map(fn ($n) => "Datacore - {$n}")->values())
            ->pluck('type_id', 'name');

        $priceMap = $this->prices->prices(self::JITA, $datacoreTypes->values()->map(fn ($id) => (int) $id)->all());
        $salesTax = $this->fees->salesTaxRate($character);

        $agents = collect($rows)->map(function (array $row) use ($agentMeta, $skillNames, $datacoreTypes, $priceMap, $salesTax) {
            $science = $skillNames[(int) $row['skill_type_id']] ?? ('Skill #'.$row['skill_type_id']);
            $datacoreTypeId = $datacoreTypes["Datacore - {$science}"] ?? null;
            // Net of the sales tax a Jita dump would pay.
            $price = ($datacoreTypeId !== null ? ($priceMap[(int) $datacoreTypeId]['buy'] ?? 0.0) : 0.0) * (1 - $salesTax);

            $points = (float) ($row['remainder_points'] ?? 0)
                + (float) ($row['points_per_day'] ?? 0)
                * CarbonImmutable::parse($row['started_at'])->diffInDays(now(), true);
            $datacores = (int) floor($points / self::RP_PER_DATACORE);
            $meta = $agentMeta->get((int) $row['agent_id']);

            return (object) [
                'agentId' => (int) $row['agent_id'],
                'science' => $science,
                'level' => $meta->level ?? null,
                'corp' => $meta->corp ?? null,
                'station' => $meta->station ?? null,
                'pointsPerDay' => (float) ($row['points_per_day'] ?? 0),
                'points' => $points,
                'datacores' => $datacores,
                'value' => $datacores * $price,
                'iskPerDay' => (float) ($row['points_per_day'] ?? 0) / self::RP_PER_DATACORE * $price,
            ];
        })->sortByDesc('value')->values();

        return (object) [
            'needsScope' => false,
            'agents' => $agents,
            'totalValue' => (float) $agents->sum('value'),
        ];
    }
}
