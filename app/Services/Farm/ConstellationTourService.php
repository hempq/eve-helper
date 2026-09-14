<?php

namespace App\Services\Farm;

use App\Services\Universe\RouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Constellation-level farming: sites respawn within the constellation
 * (community consensus), so the play is to claim a quiet constellation and
 * run it in a loop. Ranks nearby constellations and plans the optimal
 * visiting tour over every system in the chosen one.
 */
class ConstellationTourService
{
    public function __construct(
        private readonly TargetScorerService $scorer,
        private readonly RouteService $routes,
    ) {}

    private bool $avoidUnsafe = false;

    /**
     * Rank constellations by their member systems' farm scores.
     *
     * @param  Collection<int, object>  $scoredSystems  output of TargetScorerService::score()
     * @return Collection<int, object>
     */
    public function rank(Collection $scoredSystems, int $limit = 8): Collection
    {
        return $scoredSystems
            ->groupBy('constellationId')
            ->filter(fn (Collection $systems) => $systems->count() >= 2)
            ->map(fn (Collection $systems) => (object) [
                'constellationId' => $systems->first()->constellationId,
                'name' => $systems->first()->constellation,
                'region' => $systems->first()->region,
                'systems' => $systems->count(),
                'deadEnds' => $systems->where('deadEnd', true)->count(),
                'npcKills' => $systems->sum('npcKills'),
                'playerKills' => $systems->sum('playerKills'),
                'distance' => $systems->min('distance'),
                'avgScore' => round($systems->avg('score'), 1),
            ])
            ->sortByDesc('avgScore')
            ->take($limit)
            ->values();
    }

    /**
     * Optimal tour over every system of the constellation, starting from the
     * pilot's location: nearest-neighbor construction + 2-opt improvement on
     * real jump distances (constellations are small, this is near-exact).
     *
     * @return object{systems: list<object>, totalJumps: int, approachJumps: ?int}|null
     */
    public function tour(int $originSystemId, int $constellationId, bool $avoidUnsafe = false): ?object
    {
        $this->avoidUnsafe = $avoidUnsafe;

        $members = DB::table('solar_systems')
            ->where('constellation_id', $constellationId)
            ->pluck('system_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($members === []) {
            return null;
        }

        $nodes = array_values(array_unique([$originSystemId, ...$members]));
        $distance = $this->distanceMatrix($nodes);

        // Nearest-neighbor from the origin.
        $tour = [];
        $current = $originSystemId;
        $remaining = array_diff($members, [$originSystemId]);

        while ($remaining !== []) {
            $next = null;
            $best = PHP_INT_MAX;

            foreach ($remaining as $candidate) {
                $d = $distance[$current][$candidate] ?? PHP_INT_MAX;
                if ($d < $best) {
                    $best = $d;
                    $next = $candidate;
                }
            }

            if ($next === null) {
                break; // disconnected pocket
            }

            $tour[] = $next;
            $current = $next;
            $remaining = array_diff($remaining, [$next]);
        }

        if ($tour === []) {
            return null;
        }

        $tour = $this->twoOpt($tour, $originSystemId, $distance);

        // Hydrate with names + live activity, and expand every leg into the
        // actual flight path — legs may leave the constellation when that is
        // the shorter way between two members.
        [$npcKills, $shipKills, $podKills] = $this->scorer->killActivity();
        $gateCounts = $this->scorer->gateCounts();

        $systems = [];
        $fullPathIds = [$originSystemId];
        $previous = $originSystemId;
        $total = 0;
        $approach = null;

        foreach ($tour as $index => $systemId) {
            $legRoute = $this->routes->route($previous, $systemId, preferSafer: false, avoidUnsafe: $this->avoidUnsafe);
            $legJumps = $legRoute !== null ? count($legRoute) - 1 : ($distance[$previous][$systemId] ?? null);

            if ($legJumps !== null) {
                $total += $legJumps;
                $approach ??= $index === 0 ? $legJumps : null;
            }

            if ($legRoute !== null) {
                $fullPathIds = [...$fullPathIds, ...array_slice($legRoute, 1)];
            }

            $systems[] = (object) [
                'systemId' => $systemId,
                'legJumps' => $legJumps,
                'npcKills' => $npcKills[$systemId] ?? 0,
                'playerKills' => ($shipKills[$systemId] ?? 0) + ($podKills[$systemId] ?? 0),
                'deadEnd' => ($gateCounts[$systemId] ?? 0) === 1,
            ];

            $previous = $systemId;
        }

        $meta = DB::table('solar_systems')
            ->whereIn('system_id', array_unique([...$fullPathIds, ...array_column($systems, 'systemId')]))
            ->get(['system_id', 'name', 'security', 'constellation_id'])
            ->keyBy('system_id');

        foreach ($systems as $system) {
            $system->name = $meta[$system->systemId]->name ?? "#{$system->systemId}";
            $system->security = round((float) ($meta[$system->systemId]->security ?? 0), 1);
        }

        // Full path with out-of-constellation detours and revisits marked —
        // the fewer of both, the better the loop.
        $seen = [];
        $fullPath = [];
        $outside = 0;
        $revisits = 0;
        $inTourStage = false; // approach hops don't count as detours

        foreach ($fullPathIds as $id) {
            $revisit = isset($seen[$id]);
            $seen[$id] = true;
            $inConstellation = (int) ($meta[$id]->constellation_id ?? 0) === $constellationId;

            if ($inConstellation) {
                $inTourStage = true;
            } elseif ($inTourStage) {
                $outside++;
            }

            if ($inTourStage && $revisit) {
                $revisits++;
            }

            $fullPath[] = (object) [
                'systemId' => $id,
                'name' => $meta[$id]->name ?? "#{$id}",
                'security' => round((float) ($meta[$id]->security ?? 0), 1),
                'inConstellation' => $inConstellation,
                'revisit' => $revisit,
            ];
        }

        return (object) [
            'systems' => $systems,
            'totalJumps' => $total,
            'approachJumps' => $approach,
            'fullPath' => $fullPath,
            'outsideCount' => $outside,
            'revisitCount' => $revisits,
        ];
    }

    /**
     * @param  list<int>  $nodes
     * @return array<int, array<int, int>>
     */
    private function distanceMatrix(array $nodes): array
    {
        $matrix = [];

        foreach ($nodes as $a) {
            foreach ($nodes as $b) {
                if ($a === $b) {
                    $matrix[$a][$b] = 0;

                    continue;
                }

                if (isset($matrix[$b][$a])) {
                    $matrix[$a][$b] = $matrix[$b][$a];

                    continue;
                }

                $jumps = $this->routes->jumps($a, $b, preferSafer: false, avoidUnsafe: $this->avoidUnsafe);

                if ($jumps !== null) {
                    $matrix[$a][$b] = $jumps;
                }
            }
        }

        return $matrix;
    }

    /**
     * @param  list<int>  $tour
     * @param  array<int, array<int, int>>  $distance
     * @return list<int>
     */
    private function twoOpt(array $tour, int $origin, array $distance): array
    {
        $length = function (array $t) use ($origin, $distance): int {
            $total = 0;
            $prev = $origin;
            foreach ($t as $node) {
                $total += $distance[$prev][$node] ?? 1000;
                $prev = $node;
            }

            return $total;
        };

        $n = count($tour);
        $improved = true;

        while ($improved) {
            $improved = false;

            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $candidate = [
                        ...array_slice($tour, 0, $i),
                        ...array_reverse(array_slice($tour, $i, $j - $i + 1)),
                        ...array_slice($tour, $j + 1),
                    ];

                    if ($length($candidate) < $length($tour)) {
                        $tour = $candidate;
                        $improved = true;
                    }
                }
            }
        }

        return $tour;
    }
}
