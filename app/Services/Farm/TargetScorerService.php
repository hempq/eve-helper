<?php

namespace App\Services\Farm;

use App\Models\Character;
use App\Services\Universe\RouteService;
use Illuminate\Support\Facades\DB;

/**
 * "Where in this region should I farm?" — scores every system of a region
 * from public activity data. ESI exposes no anomalies/signatures, so proxies
 * carry the signal:
 *   - Supply: constellation-averaged NPC kills prove sites spawn & respawn
 *     there; the pilot's own logged sites are personal ground truth.
 *   - Vacancy, split by timescale (v7): combat anomalies respawn within
 *     minutes (constellation-wide batch respawn) and untouched signatures
 *     live for days, so AVAILABILITY — is the pocket cleared out right
 *     now? — only depends on the last couple of hours (short EWMA), while
 *     the multi-day average measures COMPETITION — is this some local's
 *     daily ratting home? A system farmed out yesterday is full again
 *     today; only the habitual farmer keeps it empty. Low gate traffic
 *     stays multi-day (habitual through-traffic). Dead-ends amplify
 *     vacancy, but ONLY when quiet right now (short window).
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
     * @return ScoredSystems scored systems in the region, best first
     */
    public function scoreRegion(
        int $regionId,
        ?Character $character = null,
        ?float $minSecurity = null,
        ?string $faction = null,
        string $securityBand = 'any',
        ?int $originSystemId = null,
    ): ScoredSystems {
        // A faction filter on a region outside that faction's space yields
        // nothing (the pirate spawn table is per region).
        if ($faction !== null && ! in_array(
            DB::table('regions')->where('region_id', $regionId)->value('name'),
            config("eve.factions.{$faction}", []),
            true,
        )) {
            return ScoredSystems::make();
        }

        return $this->scoreRegions([$regionId], $character, $minSecurity, $securityBand, $originSystemId);
    }

    /**
     * Cross-region faction tour scope: every region where the given pirate
     * faction spawns (highsec home regions AND their null home space), scored
     * as one pool.
     *
     * @param  'highsec'|'lowsec'|'nullsec'|'any'  $securityBand
     */
    public function scoreFaction(
        string $faction,
        ?Character $character = null,
        ?float $minSecurity = null,
        string $securityBand = 'any',
        ?int $originSystemId = null,
    ): ScoredSystems {
        $regionIds = DB::table('regions')
            ->whereIn('name', config("eve.factions.{$faction}", []))
            ->pluck('region_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $regionIds === []
            ? ScoredSystems::make()
            : $this->scoreRegions($regionIds, $character, $minSecurity, $securityBand, $originSystemId);
    }

    /**
     * @param  list<int>  $regionIds
     * @param  'highsec'|'lowsec'|'nullsec'|'any'  $securityBand
     */
    private function scoreRegions(
        array $regionIds,
        ?Character $character,
        ?float $minSecurity,
        string $securityBand,
        ?int $originSystemId,
    ): ScoredSystems {
        $systems = DB::table('solar_systems as s')
            ->join('constellations as c', 'c.constellation_id', '=', 's.constellation_id')
            ->join('regions as r', 'r.region_id', '=', 's.region_id')
            ->whereIn('s.region_id', $regionIds)
            ->get(['s.system_id', 's.name', 's.security', 's.constellation_id', 'c.name as constellation', 'r.name as region']);

        if ($systems->isEmpty()) {
            return ScoredSystems::make();
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

        // Short-window availability signal; without history the live hour is
        // the best "right now" estimate we have (making both timescales
        // identical, i.e. the pre-v7 behaviour).
        $recent = $usingHistory ? $this->activity->recentEwma() : ['npc' => $npcAvg, 'snapshots' => 0];
        $npcRecent = $recent['npc'];

        // Backlog/surge trends need most of a week of snapshots; until then
        // every system scores without the trend term.
        $trends = $usingHistory ? $this->activity->trends() : ['ready' => false, 'systems' => []];

        $gateCounts = $this->gateCounts();

        // Constellation-averaged NPC kills (spawn evidence), over every member.
        $constNpc = $this->constellationNpcAverage($regionIds, $npcAvg);

        // Personal per-constellation logged-site counts (30 days) and the
        // ones cleared in the last 24h (which deplete the pocket).
        $mySites = $character !== null ? $this->ownSites($character) : [];
        $recentlyCleared = $character !== null ? $this->recentlyCleared($character) : [];

        // Distances from the pilot for the small logistics term.
        $distances = $originSystemId !== null
            ? $this->routes->distancesFrom($originSystemId, 60, minSecurity: $minSecurity)
            : [];

        return $systems
            ->filter(function ($system) use ($securityBand) {
                $security = (float) $system->security;

                return match ($securityBand) {
                    'highsec' => $security >= self::HIGHSEC_LIMIT,
                    'lowsec' => $security > 0.0 && $security < self::HIGHSEC_LIMIT,
                    'nullsec' => $security <= 0.0,
                    default => true,
                };
            })
            ->map(function ($system) use (
                $npcAvg, $npcRecent, $playersAvg, $jumpsAvg, $liveShipKills, $livePodKills,
                $gateCounts, $constNpc, $mySites, $recentlyCleared, $distances, $trends
            ) {
                $id = (int) $system->system_id;
                $constellationId = (int) $system->constellation_id;
                $security = (float) $system->security;
                $isHighsec = $security >= self::HIGHSEC_LIMIT;

                $sysNpc = $npcAvg[$id] ?? 0.0;
                $sysNpcNow = $npcRecent[$id] ?? 0.0;
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

                // Own-journal ground truth, decayed: sites you logged in the
                // constellation help, but ones you recently cleared deplete it.
                $ownBonus = 3 * log1p($ownSites) - 2 * log1p($recentlyCleared[$constellationId] ?? 0);

                $supply = 10 * log1p($constNpcHere) + $ownBonus;

                // Availability (60%): quiet in the last couple of hours means
                // the fast-respawning pocket is full and nobody is taking it.
                // Competition (40%): the multi-day average flags a local's
                // daily ratting home even when they are offline right now.
                $vacancy = -0.6 * $wNpc * log1p($sysNpcNow)
                    - 0.4 * $wNpc * log1p($sysNpc)
                    - $wJumps * log1p($traffic);

                // Dead-end pockets are where unscanned combat anomalies pile
                // up (no through traffic clears them). Graded, not gated: a
                // dead-end quiet RIGHT NOW gets the full bonus even if it was
                // farmed yesterday (fast respawn), a currently busy one (a
                // local's ratting home) keeps a small share instead of nothing.
                $vacancy += match ($gates) {
                    1 => 16,
                    2 => 5,
                    default => 0,
                } * exp(-$sysNpcNow / 3);

                // Truesec: in null, more-negative security means more and
                // richer anomalies (sov Pirate Detection upgrades). Weighted
                // modestly since a high NPC baseline already proxies richness.
                $trueSec = ! $isHighsec ? 6 * max(0, -$security) : 0.0;

                $logistics = $distance !== null ? -0.5 * $distance : -20;

                // Backlog: a system usually ratted hard (spawns proven) but
                // quiet for the last half-day has uncleared sites piling up.
                // Surge: activity well above its own baseline means someone
                // is farming it out right now.
                $trend = null;
                $trendBonus = 0.0;
                $t = $trends['systems'][$id] ?? null;

                if ($t !== null && $trends['ready']) {
                    if ($t->baseline >= 3 && $t->ratio <= 0.5) {
                        $trend = 'backlog';
                        $trendBonus = 8 * log1p($t->baseline) * (1 - $t->ratio);
                    } elseif ($t->ratio >= 2 && $t->recentNorm >= 3) {
                        $trend = 'surging';
                        $trendBonus = -4 * log1p($t->recentNorm - $t->baseline);
                    }
                }

                $score = $supply + $vacancy + $trueSec + $logistics + $trendBonus;

                // Danger is a tripwire, not a tax: any live PvP kill right now
                // vetoes the system below every safe one.
                if ($liveDanger > 0) {
                    $score = min($score, 0) - 10 * $liveDanger;
                }

                return (object) [
                    'systemId' => $id,
                    'name' => $system->name,
                    'security' => round($security, 1),
                    'constellationId' => $constellationId,
                    'constellation' => $system->constellation,
                    'region' => $system->region,
                    'distance' => $distance,
                    'npcKills' => round($sysNpc, 1),
                    'npcKillsNow' => round($sysNpcNow, 1),
                    'constellationNpcKills' => round($constNpcHere, 1),
                    'playerKills' => round($playersAvg[$id] ?? 0, 1),
                    'liveDanger' => $liveDanger,
                    'traffic' => round($traffic, 1),
                    'gates' => $gates,
                    'deadEnd' => $gates === 1,
                    'ownSites' => $ownSites,
                    'trend' => $trend,
                    'score' => round($score, 1),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->pipe(fn ($c) => ScoredSystems::make($c->all()))
            ->tap(fn (ScoredSystems $c) => $c->usingHistory = $usingHistory);
    }

    /**
     * @param  list<int>  $regionIds
     * @param  array<int, float|int>  $npcBySystem
     * @return array<int, float> constellation id => avg npc kills per member
     */
    private function constellationNpcAverage(array $regionIds, array $npcBySystem): array
    {
        $members = DB::table('solar_systems')
            ->whereIn('region_id', $regionIds)
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
     * @return array<int, int> constellation id => sites the pilot cleared in
     *   the last 24h (the pocket is depleted until they respawn)
     */
    private function recentlyCleared(Character $character): array
    {
        return DB::table('signatures as sig')
            ->join('solar_systems as ss', 'ss.system_id', '=', 'sig.system_id')
            ->where('sig.character_id', $character->character_id)
            ->where('sig.status', 'done')
            ->where('sig.completed_at', '>=', now()->subDay())
            ->selectRaw('ss.constellation_id, COUNT(*) as cleared')
            ->groupBy('ss.constellation_id')
            ->pluck('cleared', 'constellation_id')
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
