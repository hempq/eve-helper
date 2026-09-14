<?php

namespace App\Services\Agents;

use App\Models\Character;
use App\Services\Universe\RouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nearby NPC mission agents and whether the pilot can actually use them.
 * Access needs effective standing (the HIGHEST of agent, corporation and
 * faction standing, Connections-adjusted) at or above the agent level's
 * requirement. Standings come from ESI (re-login needed for the scope).
 */
class AgentFinderService
{
    /** Minimum effective standing to talk to an agent of a given level. */
    private const REQUIRED = [1 => -11.0, 2 => 1.0, 3 => 3.0, 4 => 5.0, 5 => 7.0];

    private const BASIC_AGENT = 2;

    private const RESEARCH_AGENT = 4;

    public function __construct(private readonly RouteService $routes) {}

    /**
     * @return object{agents: Collection<int, object>, hasStandings: bool}
     */
    public function nearby(
        Character $character,
        int $originSystemId,
        int $minLevel = 3,
        ?int $divisionId = null,
        int $maxJumps = 15,
        int $limit = 30,
    ): object {
        $distances = $this->routes->distancesFrom(
            $originSystemId,
            $maxJumps,
            minSecurity: $character->minRouteSecurity(),
        );

        $rows = DB::table('agents as a')
            ->join('stations as st', 'st.station_id', '=', 'a.location_id')
            ->join('solar_systems as ss', 'ss.system_id', '=', 'st.system_id')
            ->leftJoin('npc_corporations as c', 'c.corporation_id', '=', 'a.corporation_id')
            ->leftJoin('factions as f', 'f.faction_id', '=', 'c.faction_id')
            ->leftJoin('npc_divisions as d', 'd.division_id', '=', 'a.division_id')
            ->whereIn('a.agent_type_id', [self::BASIC_AGENT, self::RESEARCH_AGENT])
            ->where('a.level', '>=', $minLevel)
            ->when($divisionId !== null, fn ($q) => $q->where('a.division_id', $divisionId))
            ->whereIn('st.system_id', array_keys($distances))
            ->get([
                'a.agent_id', 'a.level', 'a.division_id', 'a.is_locator', 'a.agent_type_id',
                'a.corporation_id', 'c.name as corp', 'c.faction_id', 'f.name as faction',
                'd.name as division', 'st.name as station', 'ss.system_id', 'ss.name as system', 'ss.security',
            ]);

        $standings = DB::table('character_standings')
            ->where('character_id', $character->character_id)
            ->pluck('standing', 'from_id');

        $connections = (int) DB::table('character_skills as cs')
            ->join('item_types as it', 'it.type_id', '=', 'cs.skill_id')
            ->where('cs.character_id', $character->character_id)
            ->where('it.name', 'Connections')
            ->value('cs.active_level');

        $effective = function (float $base) use ($connections): float {
            return $base > 0 ? $base + (10 - $base) * 0.04 * $connections : $base;
        };

        $agents = $rows->map(function ($row) use ($distances, $standings, $effective) {
            // Highest of agent / corp / faction standing decides access.
            $best = max(
                $effective((float) ($standings[(int) $row->agent_id] ?? 0)),
                $effective((float) ($standings[(int) $row->corporation_id] ?? 0)),
                $effective((float) ($standings[(int) ($row->faction_id ?? 0)] ?? 0)),
            );

            $required = self::REQUIRED[(int) $row->level] ?? 0.0;

            return (object) [
                'level' => (int) $row->level,
                'division' => $row->division ?? ('Division #'.$row->division_id),
                'isResearch' => (int) $row->agent_type_id === self::RESEARCH_AGENT,
                'isLocator' => (bool) $row->is_locator,
                'corp' => $row->corp ?? ('Corp #'.$row->corporation_id),
                'faction' => $row->faction,
                'station' => $row->station,
                'system' => $row->system,
                'security' => round((float) $row->security, 1),
                'distance' => $distances[(int) $row->system_id] ?? null,
                'standing' => round($best, 2),
                'required' => $required,
                'available' => $best >= $required,
            ];
        })
            ->sortBy([['available', 'desc'], ['distance', 'asc'], ['level', 'desc']])
            ->take($limit)
            ->values();

        return (object) [
            'agents' => $agents,
            'hasStandings' => $standings->isNotEmpty(),
        ];
    }

    /** @return Collection<int, object{division_id: int, name: string}> */
    public function divisions(): Collection
    {
        return DB::table('npc_divisions')->orderBy('name')->get(['division_id', 'name']);
    }
}
