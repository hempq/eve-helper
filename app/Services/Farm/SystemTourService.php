<?php

namespace App\Services\Farm;

use App\Services\Universe\RouteService;
use Illuminate\Support\Facades\DB;

/**
 * Plans an optimal visiting tour over an arbitrary set of target systems
 * (e.g. the best-scoring systems of a region), starting from the pilot's
 * location: nearest-neighbor construction + 2-opt on real jump distances,
 * then every leg is expanded into its gate-by-gate flight path. Legs may pass
 * through systems outside the target set when that is the shortest way — the
 * objective is minimal total jumps, which also minimizes re-entering systems.
 */
class SystemTourService
{
    public function __construct(
        private readonly TargetScorerService $scorer,
        private readonly RouteService $routes,
    ) {}

    private ?float $minSecurity = null;

    /**
     * @param  list<int>  $targetSystemIds
     * @return object{systems: list<object>, fullPath: list<object>, totalJumps: int,
     *   approachJumps: ?int, revisitCount: int}|null
     */
    public function tour(int $originSystemId, array $targetSystemIds, ?float $minSecurity = null): ?object
    {
        $this->minSecurity = $minSecurity;

        $targets = array_values(array_unique(array_map('intval', $targetSystemIds)));
        $targets = array_values(array_diff($targets, [$originSystemId]));

        if ($targets === []) {
            return null;
        }

        $targetSet = array_flip($targets);
        $distance = $this->distanceMatrix([$originSystemId, ...$targets]);

        // Nearest-neighbor from the origin.
        $order = [];
        $current = $originSystemId;
        $remaining = $targets;

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
                break; // disconnected under the current security constraint
            }

            $order[] = $next;
            $current = $next;
            $remaining = array_values(array_diff($remaining, [$next]));
        }

        if ($order === []) {
            return null;
        }

        $order = $this->twoOpt($order, $originSystemId, $distance);

        [$npcKills, $shipKills, $podKills] = $this->scorer->killActivity();
        $gateCounts = $this->scorer->gateCounts();

        $systems = [];
        $fullPathIds = [$originSystemId];
        $previous = $originSystemId;
        $total = 0;
        $approach = null;

        foreach ($order as $index => $systemId) {
            $legRoute = $this->routes->route($previous, $systemId, preferSafer: false, minSecurity: $this->minSecurity);
            $legJumps = $legRoute !== null ? count($legRoute) - 1 : ($distance[$previous][$systemId] ?? null);

            if ($legJumps !== null) {
                $total += $legJumps;
                if ($index === 0) {
                    $approach = $legJumps;
                }
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
            ->whereIn('system_id', array_unique([...$fullPathIds, ...$targets]))
            ->get(['system_id', 'name', 'security'])
            ->keyBy('system_id');

        foreach ($systems as $system) {
            $system->name = $meta[$system->systemId]->name ?? "#{$system->systemId}";
            $system->security = round((float) ($meta[$system->systemId]->security ?? 0), 1);
        }

        $seen = [];
        $fullPath = [];
        $revisits = 0;

        foreach ($fullPathIds as $position => $id) {
            $revisit = isset($seen[$id]);
            $seen[$id] = true;

            if ($position > 0 && $revisit) {
                $revisits++;
            }

            $fullPath[] = (object) [
                'systemId' => $id,
                'name' => $meta[$id]->name ?? "#{$id}",
                'security' => round((float) ($meta[$id]->security ?? 0), 1),
                'isTarget' => isset($targetSet[$id]),
                'revisit' => $revisit,
            ];
        }

        return (object) [
            'systems' => $systems,
            'fullPath' => $fullPath,
            'totalJumps' => $total,
            'approachJumps' => $approach,
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

                $jumps = $this->routes->jumps($a, $b, preferSafer: false, minSecurity: $this->minSecurity);
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
