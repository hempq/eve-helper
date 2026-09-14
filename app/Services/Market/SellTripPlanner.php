<?php

namespace App\Services\Market;

use App\Models\Character;
use App\Services\Universe\RouteService;
use Illuminate\Support\Facades\DB;

/**
 * Plans a multi-stop selling trip: every item is assigned to the hub where
 * it nets the most, the visiting order is optimized (exact — at most 5 hubs),
 * and a stop is dropped when its extra profit does not cover the extra jumps
 * (valued at the configured ISK-per-jump).
 */
class SellTripPlanner
{
    public function __construct(
        private readonly AppraisalService $appraisal,
        private readonly RouteService $routes,
    ) {}

    /** @var array<string, ?int> */
    private array $jumpCache = [];

    private ?float $minSecurity = null;

    /**
     * @param  array<int, int>  $typeQuantities
     * @param  'order'|'instant'  $metric
     */
    public function plan(
        Character $character,
        array $typeQuantities,
        ?int $originSystemId,
        string $metric = 'order',
        ?float $iskPerJump = null,
    ): ?SellTripPlan {
        $iskPerJump ??= (float) config('eve.market.isk_per_jump');
        $this->jumpCache = [];
        $this->minSecurity = $character->minRouteSecurity();

        $hubs = config('eve.market.hubs');
        $hubSystemIds = DB::table('solar_systems')
            ->whereIn('name', array_column($hubs, 'system'))
            ->pluck('system_id', 'name')
            ->map(fn ($id) => (int) $id);

        // Value every item at every hub.
        $netMatrix = [];   // typeId => stationId => net
        $itemInfo = [];    // typeId => AppraisalItem (any hub; name/qty identical)

        foreach ($hubs as $stationId => $hub) {
            $result = $this->appraisal->appraiseQuantities($character, $stationId, $typeQuantities);

            foreach ($result->items as $item) {
                $netMatrix[$item->typeId][$stationId] = $metric === 'instant' ? $item->instantNet : $item->orderNet;
                $itemInfo[$item->typeId] = $item;
            }
        }

        // Initial assignment: each item to its globally best hub.
        $assignment = [];
        $unsellable = [];

        foreach ($netMatrix as $typeId => $nets) {
            arsort($nets);
            $bestStation = array_key_first($nets);

            if ($nets[$bestStation] > 0) {
                $assignment[$typeId] = $bestStation;
            } else {
                $unsellable[] = $itemInfo[$typeId]->name;
            }
        }

        if ($assignment === []) {
            return null;
        }

        $tour = array_values(array_unique(array_values($assignment)));

        // Prune stops whose extra profit does not pay for the extra jumps.
        while (count($tour) > 1) {
            [, $currentJumps] = $this->bestOrder($tour, $originSystemId, $hubSystemIds, $hubs);

            $worstStation = null;
            $worstScore = 0.0;

            foreach ($tour as $station) {
                $remaining = array_values(array_diff($tour, [$station]));

                $gain = 0.0;
                foreach ($assignment as $typeId => $assigned) {
                    if ($assigned !== $station) {
                        continue;
                    }
                    $alternative = max(0.0, ...array_map(
                        fn (int $s) => $netMatrix[$typeId][$s] ?? 0.0,
                        $remaining,
                    ));
                    $gain += ($netMatrix[$typeId][$station] ?? 0.0) - $alternative;
                }

                [, $reducedJumps] = $this->bestOrder($remaining, $originSystemId, $hubSystemIds, $hubs);
                $jumpsSaved = ($currentJumps !== null && $reducedJumps !== null)
                    ? max(0, $currentJumps - $reducedJumps)
                    : 0;

                $score = $gain - $iskPerJump * $jumpsSaved;

                if ($score < $worstScore) {
                    $worstScore = $score;
                    $worstStation = $station;
                }
            }

            if ($worstStation === null) {
                break;
            }

            $tour = array_values(array_diff($tour, [$worstStation]));

            foreach ($assignment as $typeId => $assigned) {
                if ($assigned !== $worstStation) {
                    continue;
                }
                $best = null;
                $bestNet = 0.0;
                foreach ($tour as $s) {
                    $net = $netMatrix[$typeId][$s] ?? 0.0;
                    if ($net > $bestNet) {
                        $bestNet = $net;
                        $best = $s;
                    }
                }
                if ($best === null) {
                    $unsellable[] = $itemInfo[$typeId]->name;
                    unset($assignment[$typeId]);
                } else {
                    $assignment[$typeId] = $best;
                }
            }
        }

        [$order, $totalJumps] = $this->bestOrder($tour, $originSystemId, $hubSystemIds, $hubs);

        // Build the stops in visiting order.
        $stops = [];
        $previousSystem = $originSystemId;
        $totalNet = 0.0;

        foreach ($order as $stationId) {
            $stationSystem = $hubSystemIds[$hubs[$stationId]['system']] ?? null;

            $items = [];
            $stopNet = 0.0;
            foreach ($assignment as $typeId => $assigned) {
                if ($assigned !== $stationId) {
                    continue;
                }
                $net = $netMatrix[$typeId][$stationId];
                $items[] = (object) [
                    'typeId' => $typeId,
                    'name' => $itemInfo[$typeId]->name,
                    'quantity' => $itemInfo[$typeId]->quantity,
                    'net' => $net,
                ];
                $stopNet += $net;
            }
            usort($items, fn ($a, $b) => $b->net <=> $a->net);
            $totalNet += $stopNet;

            $route = ($previousSystem !== null && $stationSystem !== null)
                ? $this->routes->route($previousSystem, $stationSystem, minSecurity: $this->minSecurity)
                : null;

            $stops[] = new TripStop(
                stationId: $stationId,
                hubName: $hubs[$stationId]['name'],
                systemName: $hubs[$stationId]['system'],
                systemId: (int) ($stationSystem ?? 0),
                items: $items,
                net: $stopNet,
                jumpsFromPrevious: $route === null ? null : count($route) - 1,
                route: $route,
            );

            $previousSystem = $stationSystem;
        }

        // Baseline: sell everything at the single best hub.
        $singleBest = null;
        $singleBestNet = 0.0;
        foreach ($hubs as $stationId => $hub) {
            $net = 0.0;
            foreach ($netMatrix as $nets) {
                $net += max(0.0, $nets[$stationId] ?? 0.0);
            }
            if ($net > $singleBestNet) {
                $singleBestNet = $net;
                $singleBest = $stationId;
            }
        }

        return new SellTripPlan(
            stops: $stops,
            totalNet: $totalNet,
            totalJumps: $totalJumps,
            singleHubNet: $singleBestNet,
            singleHubJumps: $singleBest !== null && $originSystemId !== null
                ? $this->jumps($originSystemId, $hubSystemIds[$hubs[$singleBest]['system']] ?? null)
                : null,
            singleHubName: $singleBest !== null ? $hubs[$singleBest]['system'] : '—',
            metric: $metric,
            unsellableNames: array_values(array_unique($unsellable)),
        );
    }

    /**
     * Exact best visiting order for up to 5 hubs (open path from origin).
     *
     * @param  list<int>  $stations
     * @return array{0: list<int>, 1: ?int}
     */
    private function bestOrder(array $stations, ?int $originSystemId, $hubSystemIds, array $hubs): array
    {
        if ($stations === []) {
            return [[], 0];
        }

        $systemOf = fn (int $station): ?int => $hubSystemIds[$hubs[$station]['system']] ?? null;

        if ($originSystemId === null || count($stations) === 1) {
            // No routing info: keep as-is (single stop or unordered).
            $jumps = count($stations) === 1 ? $this->jumps($originSystemId, $systemOf($stations[0])) : null;

            return [$stations, $jumps];
        }

        $bestOrder = $stations;
        $bestTotal = null;

        foreach ($this->permutations($stations) as $order) {
            $total = 0;
            $from = $originSystemId;
            foreach ($order as $station) {
                $legJumps = $this->jumps($from, $systemOf($station));
                if ($legJumps === null) {
                    $total = null;
                    break;
                }
                $total += $legJumps;
                $from = $systemOf($station);
            }
            if ($total !== null && ($bestTotal === null || $total < $bestTotal)) {
                $bestTotal = $total;
                $bestOrder = $order;
            }
        }

        return [$bestOrder, $bestTotal];
    }

    private function jumps(?int $from, ?int $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return $this->jumpCache["{$from}:{$to}"] ??= $this->routes->jumps($from, $to, minSecurity: $this->minSecurity);
    }

    /**
     * @param  list<int>  $items
     * @return iterable<list<int>>
     */
    private function permutations(array $items): iterable
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $perm) {
                yield [$item, ...$perm];
            }
        }
    }
}
