<?php

namespace App\Services\Farm;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Universe\RouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Where should I go farming?" — scores systems in jump range using public
 * ESI activity data: NPC kills prove sites are being run and respawning,
 * player kills and traffic mean competition and danger. Sites themselves are
 * not exposed by ESI, so proxies are the best any tool can do.
 */
class TargetScorerService
{
    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly RouteService $routes,
    ) {}

    /**
     * @param  'highsec'|'lowsec'|'nullsec'|'any'  $securityBand
     * @param  list<array{0: int, 1: int}>  $extraEdges
     * @return Collection<int, object> scored systems, best first
     */
    public function score(
        int $originSystemId,
        int $maxJumps = 10,
        string $securityBand = 'any',
        ?string $faction = null,
        array $extraEdges = [],
    ): Collection {
        $distances = $this->routes->distancesFrom($originSystemId, $maxJumps, $extraEdges);
        unset($distances[$originSystemId]);

        if ($distances === []) {
            return collect();
        }

        [$npcKills, $shipKills, $podKills] = $this->killActivity();
        $traffic = $this->jumpActivity();

        $factionRegions = $faction !== null
            ? array_flip(config("eve.factions.{$faction}", []))
            : null;

        $systems = DB::table('solar_systems as s')
            ->join('regions as r', 'r.region_id', '=', 's.region_id')
            ->join('constellations as c', 'c.constellation_id', '=', 's.constellation_id')
            ->whereIn('s.system_id', array_keys($distances))
            ->get(['s.system_id', 's.name', 's.security', 'c.name as constellation', 'r.name as region']);

        return $systems
            ->filter(function ($system) use ($securityBand, $factionRegions) {
                $security = (float) $system->security;

                $bandOk = match ($securityBand) {
                    'highsec' => $security >= 0.45,
                    'lowsec' => $security > 0.0 && $security < 0.45,
                    'nullsec' => $security <= 0.0,
                    default => true,
                };

                return $bandOk && ($factionRegions === null || isset($factionRegions[$system->region]));
            })
            ->map(function ($system) use ($distances, $npcKills, $shipKills, $podKills, $traffic) {
                $id = (int) $system->system_id;
                $npc = $npcKills[$id] ?? 0;
                $players = ($shipKills[$id] ?? 0) + ($podKills[$id] ?? 0);
                $jumps = $traffic[$id] ?? 0;
                $distance = $distances[$id];

                // Activity proves respawning sites; competition and danger
                // subtract; nearby beats far.
                $score = 10 * log1p($npc)
                    - 12 * $players
                    - $jumps / 50
                    - $distance;

                return (object) [
                    'systemId' => $id,
                    'name' => $system->name,
                    'security' => round((float) $system->security, 1),
                    'constellation' => $system->constellation,
                    'region' => $system->region,
                    'distance' => $distance,
                    'npcKills' => $npc,
                    'playerKills' => $players,
                    'traffic' => $jumps,
                    'score' => round($score, 1),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @return array{0: array<int,int>, 1: array<int,int>, 2: array<int,int>}
     */
    private function killActivity(): array
    {
        try {
            $rows = $this->esi->get('/universe/system_kills')->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [[], [], []];
        }

        $npc = $ship = $pod = [];

        foreach ($rows as $row) {
            $id = (int) $row['system_id'];
            $npc[$id] = (int) ($row['npc_kills'] ?? 0);
            $ship[$id] = (int) ($row['ship_kills'] ?? 0);
            $pod[$id] = (int) ($row['pod_kills'] ?? 0);
        }

        return [$npc, $ship, $pod];
    }

    /**
     * @return array<int, int>
     */
    private function jumpActivity(): array
    {
        try {
            $rows = $this->esi->get('/universe/system_jumps')->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [];
        }

        $traffic = [];

        foreach ($rows as $row) {
            $traffic[(int) $row['system_id']] = (int) ($row['ship_jumps'] ?? 0);
        }

        return $traffic;
    }
}
