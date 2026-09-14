<?php

namespace App\Services\Universe;

use Illuminate\Support\Facades\DB;
use SplPriorityQueue;

/**
 * Route planning on the stargate graph imported from the SDE (~8.5k systems,
 * ~14k gate pairs). Dijkstra with a security penalty implements the in-game
 * "prefer safer" behaviour: low-sec/null systems cost 50 jumps' worth, so
 * they are only crossed when there is no high-sec alternative.
 */
class RouteService
{
    private const UNSAFE_PENALTY = 50;

    private const HIGHSEC_LIMIT = 0.45;

    /** @var array<int, list<int>>|null */
    private ?array $adjacency = null;

    /** @var array<int, float>|null */
    private ?array $securities = null;

    /**
     * Systems along the route including both endpoints, or null when
     * unreachable (different islands, unknown ids). With $avoidUnsafe,
     * low/null-sec systems are excluded from the graph entirely — a
     * destination below the high-sec limit becomes unreachable.
     *
     * @return list<int>|null
     */
    public function route(int $fromSystemId, int $toSystemId, bool $preferSafer = true, bool $avoidUnsafe = false): ?array
    {
        $this->load();

        if (! isset($this->adjacency[$fromSystemId]) && $fromSystemId !== $toSystemId) {
            return null;
        }

        if ($fromSystemId === $toSystemId) {
            return [$fromSystemId];
        }

        $dist = [$fromSystemId => 0.0];
        $prev = [];
        $queue = new SplPriorityQueue;
        $queue->insert($fromSystemId, 0.0);

        while (! $queue->isEmpty()) {
            $system = $queue->extract();

            if ($system === $toSystemId) {
                break;
            }

            foreach ($this->adjacency[$system] ?? [] as $neighbor) {
                $unsafe = ($this->securities[$neighbor] ?? 1.0) < self::HIGHSEC_LIMIT;

                if ($avoidUnsafe && $unsafe) {
                    continue;
                }

                $cost = 1.0;

                if ($preferSafer && $unsafe) {
                    $cost += self::UNSAFE_PENALTY;
                }

                $candidate = $dist[$system] + $cost;

                if ($candidate < ($dist[$neighbor] ?? INF)) {
                    $dist[$neighbor] = $candidate;
                    $prev[$neighbor] = $system;
                    $queue->insert($neighbor, -$candidate);
                }
            }
        }

        if (! isset($prev[$toSystemId])) {
            return null;
        }

        $path = [$toSystemId];
        while (end($path) !== $fromSystemId) {
            $path[] = $prev[end($path)];
        }

        return array_reverse($path);
    }

    public function jumps(int $fromSystemId, int $toSystemId, bool $preferSafer = true, bool $avoidUnsafe = false): ?int
    {
        $route = $this->route($fromSystemId, $toSystemId, $preferSafer, $avoidUnsafe);

        return $route === null ? null : count($route) - 1;
    }

    /**
     * Jump distance to every system reachable within $maxJumps (plain BFS,
     * no security weighting — used for "what is near me" scans).
     *
     * @param  list<array{0: int, 1: int}>  $extraEdges  temporary bidirectional
     *         connections (e.g. live Thera/Turnur wormholes)
     * @return array<int, int> system id => jumps
     */
    public function distancesFrom(int $fromSystemId, int $maxJumps, array $extraEdges = [], bool $avoidUnsafe = false): array
    {
        $this->load();

        $adjacency = $this->adjacency;
        foreach ($extraEdges as [$a, $b]) {
            $adjacency[$a][] = $b;
            $adjacency[$b][] = $a;
        }

        $distances = [$fromSystemId => 0];
        $frontier = [$fromSystemId];

        for ($depth = 1; $depth <= $maxJumps && $frontier !== []; $depth++) {
            $next = [];

            foreach ($frontier as $system) {
                foreach ($adjacency[$system] ?? [] as $neighbor) {
                    if ($avoidUnsafe && ($this->securities[$neighbor] ?? 1.0) < self::HIGHSEC_LIMIT) {
                        continue;
                    }

                    if (! isset($distances[$neighbor])) {
                        $distances[$neighbor] = $depth;
                        $next[] = $neighbor;
                    }
                }
            }

            $frontier = $next;
        }

        return $distances;
    }

    private function load(): void
    {
        if ($this->adjacency !== null) {
            return;
        }

        $this->adjacency = [];

        foreach (DB::table('system_jumps')->get() as $jump) {
            $this->adjacency[(int) $jump->from_system_id][] = (int) $jump->to_system_id;
        }

        $this->securities = DB::table('solar_systems')
            ->pluck('security', 'system_id')
            ->map(floatval(...))
            ->all();
    }
}
