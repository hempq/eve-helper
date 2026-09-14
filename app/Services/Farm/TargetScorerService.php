<?php

namespace App\Services\Farm;

use App\Models\Character;
use App\Services\Universe\RouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Where in this region should I farm?" — scores every system of a region
 * from public activity data. ESI exposes no anomalies/signatures, so proxies
 * carry the signal:
 *   - Supply: constellation-averaged NPC kills prove sites spawn & respawn
 *     there; the pilot's own logged sites are personal ground truth.
 *   - Vacancy: low in-system NPC kills and low gate traffic mean nobody is
 *     clearing sites, so unscanned ones pile up — averaged over a history
 *     window because a single ESI hour is very noisy. Dead-ends amplify this,
 *     but ONLY when their kill history is near zero (else it is a local's
 *     ratting home, per explorer guides).
 *   - Danger: the live ship/pod kill snapshot is a "camp right now" tripwire.
 * Highsec inverts the emphasis: NPC kills there are polluted by mission and
 * incursion hubs, so gate traffic becomes the primary vacancy signal.
 */
class TargetScorerService
{
    private const HIGHSEC_LIMIT = 0.45;

    public function __construct(
        private readonly ActivitySource $source,
        private readonly RouteService $routes,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  'highsec'|'lowsec'|'nullsec'|'any'  $securityBand
     * @return Collection<int, object> scored systems in the region, best first
     */
    public function scoreRegion(
        int $regionId,
        ?Character $character = null,
        ?float $minSecurity = null,
        ?string $faction = null,
        string $securityBand = 'any',
        ?int $originSystemId = null,
    ): Collection {
        $systems = DB::table('solar_systems as s')
            ->join('constellations as c', 'c.constellation_id', '=', 's.constellation_id')
            ->where('s.region_id', $regionId)
            ->get(['s.system_id', 's.name', 's.security', 's.constellation_id', 'c.name as constellation']);

        if ($systems->isEmpty()) {
            return collect();
        }

        // Prefer averaged history; fall back to the live snapshot when the
        // history table is still empty.
        $avg = $this->activity->averages(72);
        if ($avg['snapshots'] > 0) {
            $npcAvg = $avg['npc'];
            $playersAvg = $avg['players'];
            $jumpsAvg = $avg['jumps'];
            $usingHistory = true;
        } else {
            [$liveNpc, $liveShip, $livePod] = $this->killActivity();
            $npcAvg = $liveNpc;
            $playersAvg = [];
            foreach ($liveShip as $id => $s) {
                $playersAvg[$id] = $s + ($livePod[$id] ?? 0);
            }
            $jumpsAvg = $this->jumpActivity();
            $usingHistory = false;
        }

        // Live snapshot is always the danger tripwire, regardless of history.
        [, $liveShipKills, $livePodKills] = $this->killActivity();

        $gateCounts = $this->gateCounts();

        // Constellation-averaged NPC kills (spawn evidence), over every member.
        $constNpc = $this->constellationNpcAverage($regionId, $npcAvg);

        // Personal per-constellation logged-site counts (30 days).
        $mySites = $character !== null ? $this->ownSites($character) : [];

        // Distances from the pilot for the small logistics term.
        $distances = $originSystemId !== null
            ? $this->routes->distancesFrom($originSystemId, 60, minSecurity: $minSecurity)
            : [];

        $factionRegionOk = $faction === null
            || in_array(
                DB::table('regions')->where('region_id', $regionId)->value('name'),
                config("eve.factions.{$faction}", []),
                true,
            );

        return $systems
            ->filter(function ($system) use ($securityBand, $factionRegionOk) {
                $security = (float) $system->security;
                $bandOk = match ($securityBand) {
                    'highsec' => $security >= self::HIGHSEC_LIMIT,
                    'lowsec' => $security > 0.0 && $security < self::HIGHSEC_LIMIT,
                    'nullsec' => $security <= 0.0,
                    default => true,
                };

                return $bandOk && $factionRegionOk;
            })
            ->map(function ($system) use (
                $npcAvg, $playersAvg, $jumpsAvg, $liveShipKills, $livePodKills,
                $gateCounts, $constNpc, $mySites, $distances
            ) {
                $id = (int) $system->system_id;
                $constellationId = (int) $system->constellation_id;
                $security = (float) $system->security;
                $isHighsec = $security >= self::HIGHSEC_LIMIT;

                $sysNpc = $npcAvg[$id] ?? 0.0;
                $traffic = $jumpsAvg[$id] ?? 0.0;
                $constNpcHere = $constNpc[$constellationId] ?? 0.0;
                $gates = $gateCounts[$id] ?? 0;
                $ownSites = min(10, $mySites[$constellationId] ?? 0);
                $liveDanger = min(20, ($liveShipKills[$id] ?? 0) + ($livePodKills[$id] ?? 0));
                $distance = $distances[$id] ?? null;

                // Highsec NPC kills are mission/incursion-polluted -> lean on
                // traffic for vacancy; elsewhere NPC kills are the real
                // "someone is farming here" signal.
                [$wNpc, $wJumps] = $isHighsec ? [3.0, 6.0] : [8.0, 3.0];

                $supply = 10 * log1p($constNpcHere) + 3 * log1p($ownSites);
                $vacancy = -$wNpc * log1p($sysNpc) - $wJumps * log1p($traffic);

                // Dead-end bonus only when the system is genuinely quiet.
                if ($sysNpc < 1.0) {
                    $vacancy += match ($gates) {
                        1 => 12,
                        2 => 4,
                        default => 0,
                    };
                }

                $danger = -6 * $liveDanger;
                $logistics = $distance !== null ? -0.5 * $distance : -20;

                $score = $supply + $vacancy + $danger + $logistics;

                return (object) [
                    'systemId' => $id,
                    'name' => $system->name,
                    'security' => round($security, 1),
                    'constellationId' => $constellationId,
                    'constellation' => $system->constellation,
                    'distance' => $distance,
                    'npcKills' => round($sysNpc, 1),
                    'constellationNpcKills' => round($constNpcHere, 1),
                    'playerKills' => round($playersAvg[$id] ?? 0, 1),
                    'liveDanger' => $liveDanger,
                    'traffic' => round($traffic, 1),
                    'gates' => $gates,
                    'deadEnd' => $gates === 1,
                    'ownSites' => $ownSites,
                    'score' => round($score, 1),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->tap(fn ($c) => $c->usingHistory = $usingHistory);
    }

    /**
     * @param  array<int, float|int>  $npcBySystem
     * @return array<int, float> constellation id => avg npc kills per member
     */
    private function constellationNpcAverage(int $regionId, array $npcBySystem): array
    {
        $members = DB::table('solar_systems')
            ->where('region_id', $regionId)
            ->get(['system_id', 'constellation_id']);

        $result = [];
        foreach ($members->groupBy('constellation_id') as $constellationId => $group) {
            $sum = 0.0;
            foreach ($group as $member) {
                $sum += $npcBySystem[(int) $member->system_id] ?? 0;
            }
            $result[(int) $constellationId] = $sum / max(1, $group->count());
        }

        return $result;
    }

    /**
     * @return array<int, int> constellation id => logged sites (30d)
     */
    private function ownSites(Character $character): array
    {
        return DB::table('signatures as sig')
            ->join('solar_systems as ss', 'ss.system_id', '=', 'sig.system_id')
            ->where('sig.character_id', $character->character_id)
            ->where('sig.first_seen', '>=', now()->subDays(30))
            ->selectRaw('ss.constellation_id, COUNT(*) as sites')
            ->groupBy('ss.constellation_id')
            ->pluck('sites', 'constellation_id')
            ->map(fn ($v) => (int) $v)
            ->all();
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
        return $this->source->killActivity();
    }

    /**
     * @return array<int, int>
     */
    public function jumpActivity(): array
    {
        return $this->source->jumpActivity();
    }
}
