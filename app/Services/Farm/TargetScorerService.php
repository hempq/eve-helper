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
     * Score v2 — "a quiet system in a productive constellation": sites
     * respawn constellation-wide, so constellation-level NPC activity is
     * spawn evidence while same-system activity is live competition. The
     * pilot's own signature journal feeds a personal per-constellation bonus.
     *
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
        ?\App\Models\Character $character = null,
        bool $avoidUnsafe = false,
    ): Collection {
        $distances = $this->routes->distancesFrom($originSystemId, $maxJumps, $extraEdges, $avoidUnsafe);
        unset($distances[$originSystemId]);

        if ($distances === []) {
            return collect();
        }

        [$npcKills, $shipKills, $podKills] = $this->killActivity();
        $traffic = $this->jumpActivity();
        $gateCounts = $this->gateCounts();

        $factionRegions = $faction !== null
            ? array_flip(config("eve.factions.{$faction}", []))
            : null;

        $systems = DB::table('solar_systems as s')
            ->join('regions as r', 'r.region_id', '=', 's.region_id')
            ->join('constellations as c', 'c.constellation_id', '=', 's.constellation_id')
            ->whereIn('s.system_id', array_keys($distances))
            ->get(['s.system_id', 's.name', 's.security', 's.constellation_id',
                'c.name as constellation', 'r.name as region']);

        // Constellation-level NPC activity per member system: computed over
        // ALL members (even out of range), since respawns roam the whole
        // constellation.
        $constellationIds = $systems->pluck('constellation_id')->unique();
        $members = DB::table('solar_systems')
            ->whereIn('constellation_id', $constellationIds)
            ->get(['system_id', 'constellation_id']);

        $constNpcPerSystem = [];
        foreach ($members->groupBy('constellation_id') as $constellationId => $group) {
            $sum = 0;
            foreach ($group as $member) {
                $sum += $npcKills[(int) $member->system_id] ?? 0;
            }
            $constNpcPerSystem[$constellationId] = $sum / max(1, $group->count());
        }

        // Personal evidence: sites the pilot logged per constellation (30d).
        $mySites = [];
        if ($character !== null) {
            $mySites = DB::table('signatures as sig')
                ->join('solar_systems as ss', 'ss.system_id', '=', 'sig.system_id')
                ->where('sig.character_id', $character->character_id)
                ->where('sig.first_seen', '>=', now()->subDays(30))
                ->selectRaw('ss.constellation_id, COUNT(*) as sites')
                ->groupBy('ss.constellation_id')
                ->pluck('sites', 'constellation_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

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
            ->map(function ($system) use ($distances, $npcKills, $shipKills, $podKills, $traffic, $gateCounts, $constNpcPerSystem, $mySites) {
                $id = (int) $system->system_id;
                $constellationId = (int) $system->constellation_id;
                $npc = $npcKills[$id] ?? 0;
                $players = ($shipKills[$id] ?? 0) + ($podKills[$id] ?? 0);
                $jumps = $traffic[$id] ?? 0;
                $distance = $distances[$id];
                $gates = $gateCounts[$id] ?? 0;
                $constNpc = $constNpcPerSystem[$constellationId] ?? 0.0;
                $ownSites = min(10, $mySites[$constellationId] ?? 0);

                // Quiet system in a productive constellation. Pilot presence
                // is the enemy of standing anomalies: ESI has no pilot
                // count, so its two proxies — gate traffic and in-system
                // NPC kills (someone is ratting right there) — both take a
                // strong logarithmic penalty. Dead-end pockets weigh
                // heavily: with no through traffic, unscanned combat
                // anomalies pile up there.
                $score = 10 * log1p($constNpc)
                    - 5 * log1p($npc)
                    - 5 * log1p($jumps)
                    - 12 * $players
                    - $distance
                    + match ($gates) {
                        1 => 18,
                        2 => 6,
                        default => 0,
                    }
                    + 2 * $ownSites;

                return (object) [
                    'systemId' => $id,
                    'name' => $system->name,
                    'security' => round((float) $system->security, 1),
                    'constellationId' => $constellationId,
                    'constellation' => $system->constellation,
                    'region' => $system->region,
                    'distance' => $distance,
                    'npcKills' => $npc,
                    'constellationNpcKills' => (int) round($constNpc),
                    'playerKills' => $players,
                    'traffic' => $jumps,
                    'gates' => $gates,
                    'deadEnd' => $gates === 1,
                    'ownSites' => $ownSites,
                    'score' => round($score, 1),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @return array<int, int> system id => number of stargate connections
     */
    public function gateCounts(): array
    {
        return DB::table('system_jumps')
            ->selectRaw('from_system_id, COUNT(*) as gates')
            ->groupBy('from_system_id')
            ->pluck('gates', 'from_system_id')
            ->map(fn ($g) => (int) $g)
            ->all();
    }

    /**
     * @return array{0: array<int,int>, 1: array<int,int>, 2: array<int,int>}
     */
    public function killActivity(): array
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
    public function jumpActivity(): array
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
