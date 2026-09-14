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

    private const GRASP_RESTARTS = 60;

    /** Restricted candidate list size for the randomized greedy pick. */
    private const RCL_SIZE = 4;

    /** Score a jump must earn for a stop to be worth reaching (selection). */
    private const JUMP_PENALTY = 1.0;

    private ?float $minSecurity = null;

    /**
     * Orienteering tour: from a pool of scored candidate systems, pick up to
     * $count that maximize collected score per jump, then order them for the
     * fewest jumps. Unlike a pure shortest-tour over a fixed target set, this
     * deliberately detours into high-value dead-ends — the extra in-and-out
     * jumps are paid for by the system's score.
     *
     * @param  array<int, float>  $candidates  system id => farm score
     * @return object{systems: list<object>, fullPath: list<object>, totalJumps: int,
     *   approachJumps: ?int, revisitCount: int}|null
     */
    public function tour(int $originSystemId, array $candidates, int $count = 8, ?float $minSecurity = null): ?object
    {
        $this->minSecurity = $minSecurity;

        unset($candidates[$originSystemId]);

        if ($candidates === [] || $count < 1) {
            return null;
        }

        $ids = array_map('intval', array_keys($candidates));
        $distance = $this->distanceMatrix([$originSystemId, ...$ids]);

        // Orienteering Problem, single pilot. GRASP multi-start: many
        // randomized greedy constructions (Tsiligirides desirability
        // score²/Δjumps, restricted candidate list) each polished with 2-opt
        // + Or-opt; keep the route collecting the most score. Deterministic
        // seed so the same inputs give the same tour.
        mt_srand(crc32($originSystemId.':'.implode(',', $ids)));

        $best = null;
        $bestValue = -INF;

        for ($restart = 0; $restart < self::GRASP_RESTARTS; $restart++) {
            $order = $this->construct($candidates, $originSystemId, $distance, $count);

            if ($order === []) {
                continue;
            }

            $order = $this->localSearch($order, $originSystemId, $distance);

            // Maximize collected score minus a jump cost, so distance shapes
            // WHICH systems are chosen (a far high-score dead-end is worth a
            // detour; a far marginal one is not), not just their order.
            $value = array_sum(array_map(fn (int $id) => $candidates[$id], $order))
                - self::JUMP_PENALTY * $this->routeLength($order, $originSystemId, $distance);

            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $order;
            }
        }

        if ($best === null) {
            return null;
        }

        $order = $best;
        $targetSet = array_flip($order);

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
            ->whereIn('system_id', array_unique([...$fullPathIds, ...$order]))
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
     * One randomized greedy construction: repeatedly insert from a restricted
     * candidate list ranked by Tsiligirides desirability score²/Δjumps.
     *
     * @param  array<int, float>  $candidates
     * @param  array<int, array<int, int>>  $distance
     * @return list<int>
     */
    private function construct(array $candidates, int $origin, array $distance, int $count): array
    {
        $order = [];
        $remaining = $candidates;

        while (count($order) < $count && $remaining !== []) {
            $ranked = [];

            foreach ($remaining as $id => $score) {
                [$cost, $pos] = $this->cheapestInsertion($order, (int) $id, $origin, $distance);

                if ($cost === null) {
                    continue;
                }

                // Squared score biases toward high-value systems so cheap
                // low-score pockets don't crowd the route out.
                $ranked[] = ['id' => (int) $id, 'pos' => $pos, 'desire' => ($score * $score) / max(1, $cost)];
            }

            if ($ranked === []) {
                break;
            }

            usort($ranked, fn ($a, $b) => $b['desire'] <=> $a['desire']);
            $pick = $ranked[mt_rand(0, min(self::RCL_SIZE, count($ranked)) - 1)];

            array_splice($order, $pick['pos'], 0, [$pick['id']]);
            unset($remaining[$pick['id']]);
        }

        return $order;
    }

    /**
     * Local search to convergence: 2-opt (reverse a segment) + Or-opt
     * (relocate a chain of 1-3 systems) on the open path from the origin.
     *
     * @param  list<int>  $order
     * @param  array<int, array<int, int>>  $distance
     * @return list<int>
     */
    private function localSearch(array $order, int $origin, array $distance): array
    {
        $order = $this->twoOpt($order, $origin, $distance);

        $improved = true;
        while ($improved) {
            $improved = false;
            $n = count($order);
            $base = $this->routeLength($order, $origin, $distance);

            for ($len = 1; $len <= 3 && ! $improved; $len++) {
                for ($i = 0; $i + $len <= $n && ! $improved; $i++) {
                    $chain = array_slice($order, $i, $len);
                    $rest = [...array_slice($order, 0, $i), ...array_slice($order, $i + $len)];

                    for ($j = 0; $j <= count($rest); $j++) {
                        if ($j === $i) {
                            continue;
                        }
                        $candidate = [...array_slice($rest, 0, $j), ...$chain, ...array_slice($rest, $j)];

                        if ($this->routeLength($candidate, $origin, $distance) < $base) {
                            $order = $this->twoOpt($candidate, $origin, $distance);
                            $improved = true;
                            break;
                        }
                    }
                }
            }
        }

        return $order;
    }

    /**
     * @param  list<int>  $order
     * @param  array<int, array<int, int>>  $distance
     */
    private function routeLength(array $order, int $origin, array $distance): int
    {
        $total = 0;
        $prev = $origin;
        foreach ($order as $node) {
            $total += $distance[$prev][$node] ?? 1000;
            $prev = $node;
        }

        return $total;
    }

    /**
     * Cheapest place to insert $id into the open path origin→order, and the
     * marginal jumps it costs. Returns [null, 0] when $id is unreachable.
     *
     * @param  list<int>  $order
     * @param  array<int, array<int, int>>  $distance
     * @return array{0: ?int, 1: int}
     */
    private function cheapestInsertion(array $order, int $id, int $origin, array $distance): array
    {
        $d = fn (int $a, int $b): ?int => $distance[$a][$b] ?? null;

        // Append after the origin when the path is empty.
        if ($order === []) {
            $cost = $d($origin, $id);

            return $cost === null ? [null, 0] : [$cost, 0];
        }

        $bestCost = null;
        $bestPos = count($order);

        $sequence = [$origin, ...$order];

        // Insert between consecutive nodes.
        for ($i = 0; $i < count($sequence) - 1; $i++) {
            $a = $sequence[$i];
            $b = $sequence[$i + 1];
            $ac = $d($a, $id);
            $cb = $d($id, $b);
            $ab = $d($a, $b);

            if ($ac === null || $cb === null || $ab === null) {
                continue;
            }

            $delta = $ac + $cb - $ab;
            if ($bestCost === null || $delta < $bestCost) {
                $bestCost = $delta;
                $bestPos = $i; // insert before order[$i]
            }
        }

        // Or append at the end.
        $tailCost = $d(end($sequence), $id);
        if ($tailCost !== null && ($bestCost === null || $tailCost < $bestCost)) {
            $bestCost = $tailCost;
            $bestPos = count($order);
        }

        return [$bestCost, $bestPos];
    }

    /**
     * All-pairs jump distances among the tour nodes. One BFS per node
     * (distancesFrom) rather than a Dijkstra per pair — an order of magnitude
     * cheaper for a pool of ~30 candidates.
     *
     * @param  list<int>  $nodes
     * @return array<int, array<int, int>>
     */
    private function distanceMatrix(array $nodes): array
    {
        $wanted = array_flip($nodes);
        $matrix = [];

        foreach ($nodes as $a) {
            $distances = $this->routes->distancesFrom($a, 100, minSecurity: $this->minSecurity);

            foreach ($distances as $system => $jumps) {
                if (isset($wanted[$system])) {
                    $matrix[$a][$system] = $jumps;
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
