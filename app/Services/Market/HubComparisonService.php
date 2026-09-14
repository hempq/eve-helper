<?php

namespace App\Services\Market;

use App\Models\Character;
use App\Services\Universe\RouteService;
use Illuminate\Support\Facades\DB;

/**
 * Values one basket of items at every trade hub and attaches the safer-route
 * jump count from where the items sit — "where should I haul this to?".
 */
class HubComparisonService
{
    public function __construct(
        private readonly AppraisalService $appraisal,
        private readonly RouteService $routes,
    ) {}

    /**
     * @param  array<int, int>  $typeQuantities  type id => quantity
     * @param  'order'|'instant'  $metric  rank hubs by sell-order net or by
     *                                     instant (hit buy orders) net
     * @return array{options: list<HubOption>, best: ?HubOption}
     */
    public function compare(Character $character, array $typeQuantities, ?int $originSystemId, string $metric = 'order'): array
    {
        $hubSystemIds = DB::table('solar_systems')
            ->whereIn('name', array_column(config('eve.market.hubs'), 'system'))
            ->pluck('system_id', 'name');

        $options = [];

        foreach (config('eve.market.hubs') as $stationId => $hub) {
            $result = $this->appraisal->appraiseQuantities($character, $stationId, $typeQuantities);
            $systemId = (int) ($hubSystemIds[$hub['system']] ?? 0);

            $route = ($originSystemId !== null && $systemId !== 0)
                ? $this->routes->route($originSystemId, $systemId, avoidUnsafe: $character->avoidsLowsec())
                : null;

            $options[] = new HubOption(
                stationId: $stationId,
                hubName: $hub['name'],
                systemName: $hub['system'],
                systemId: $systemId,
                orderNet: $result->totalOrderNet(),
                instantNet: $result->totalInstantNet(),
                jumps: $route === null ? null : count($route) - 1,
                route: $route,
            );
        }

        $value = fn (HubOption $o): float => $metric === 'instant' ? $o->instantNet : $o->orderNet;

        usort($options, fn (HubOption $a, HubOption $b) => $value($b) <=> $value($a));

        $best = $options[0] ?? null;

        return [
            'options' => $options,
            'best' => $best !== null && $value($best) > 0 ? $best : null,
        ];
    }
}
