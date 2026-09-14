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
     * unreachable (different islands, unknown ids).
     *
     * @return list<int>|null
     */
    public function route(int $fromSystemId, int $toSystemId, bool $preferSafer = true): ?array
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
                $cost = 1.0;

                if ($preferSafer && ($this->securities[$neighbor] ?? 1.0) < self::HIGHSEC_LIMIT) {
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

    public function jumps(int $fromSystemId, int $toSystemId, bool $preferSafer = true): ?int
    {
        $route = $this->route($fromSystemId, $toSystemId, $preferSafer);

        return $route === null ? null : count($route) - 1;
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
