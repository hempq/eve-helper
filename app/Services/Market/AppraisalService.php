<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Facades\DB;

class AppraisalService
{
    /** Cap history lookups per appraisal (each is an ESI call, cached daily). */
    private const HISTORY_LOOKUP_CAP = 60;

    public function __construct(
        private readonly PasteParser $parser,
        private readonly PriceProviderInterface $prices,
        private readonly TradeFeeService $fees,
        private readonly MarketHistoryService $history,
    ) {}

    public function appraise(Character $character, int $stationId, string $text): AppraisalResult
    {
        $parsed = $this->parser->parse($text);

        $types = $parsed['items'] === [] ? collect() : DB::table('item_types')
            ->whereIn(DB::raw('LOWER(name)'), array_map(mb_strtolower(...), array_keys($parsed['items'])))
            ->get(['type_id', 'name', 'volume'])
            ->keyBy(fn ($t) => mb_strtolower($t->name));

        $unknown = [];
        $typeQuantities = [];

        foreach ($parsed['items'] as $name => $quantity) {
            $type = $types->get(mb_strtolower($name));

            if ($type === null) {
                $unknown[] = $name;

                continue;
            }

            $typeQuantities[(int) $type->type_id] = ($typeQuantities[(int) $type->type_id] ?? 0) + $quantity;
        }

        return $this->appraiseQuantities($character, $stationId, $typeQuantities, $unknown, $parsed['unparsed']);
    }

    /**
     * @param  array<int, int>  $typeQuantities  type id => quantity
     * @param  list<string>  $unknownNames
     * @param  list<string>  $unparsedLines
     */
    public function appraiseQuantities(
        Character $character,
        int $stationId,
        array $typeQuantities,
        array $unknownNames = [],
        array $unparsedLines = [],
    ): AppraisalResult {
        $types = $typeQuantities === [] ? collect() : DB::table('item_types')
            ->whereIn('type_id', array_keys($typeQuantities))
            ->get(['type_id', 'name', 'volume'])
            ->keyBy('type_id');

        $priceMap = $typeQuantities === []
            ? []
            : $this->prices->prices($stationId, array_keys($typeQuantities));

        $salesTax = $this->fees->salesTaxRate($character);
        $brokerFee = $this->fees->brokerFeeRate($character);

        $regionId = config('eve.market.history_enabled') ? $this->regionOfStation($stationId) : null;
        // Look up liquidity for the most valuable items only (each is a
        // cached ESI history call).
        $volumeTypes = $regionId !== null ? $this->highestValueTypes($typeQuantities, $priceMap) : [];

        $items = [];

        foreach ($typeQuantities as $typeId => $quantity) {
            $type = $types->get($typeId);

            if ($type === null) {
                continue;
            }

            $buy = $priceMap[$typeId]['buy'] ?? 0.0;
            $sell = $priceMap[$typeId]['sell'] ?? 0.0;

            $avgDailyVolume = ($regionId !== null && isset($volumeTypes[$typeId]))
                ? $this->history->averageDailyVolume($regionId, (int) $typeId)
                : null;

            $items[] = new AppraisalItem(
                typeId: $typeId,
                name: $type->name,
                quantity: $quantity,
                volume: $type->volume !== null ? (float) $type->volume : null,
                buyPrice: $buy,
                sellPrice: $sell,
                // Hitting buy orders: sales tax only.
                instantNet: $buy * $quantity * (1 - $salesTax),
                // Listing a sell order: sales tax + broker fee.
                orderNet: $sell * $quantity * (1 - $salesTax - $brokerFee),
                avgDailyVolume: $avgDailyVolume,
            );
        }

        usort($items, fn (AppraisalItem $a, AppraisalItem $b) => $b->orderNet <=> $a->orderNet);

        return $this->buildResult($stationId, $items, $unknownNames, $unparsedLines, $salesTax, $brokerFee);
    }

    /**
     * @param  list<AppraisalItem>  $items
     * @param  list<string>  $unknownNames
     * @param  list<string>  $unparsedLines
     */
    private function buildResult(int $stationId, array $items, array $unknownNames, array $unparsedLines, float $salesTax, float $brokerFee): AppraisalResult
    {
        return new AppraisalResult(
            stationId: $stationId,
            items: $items,
            unknownNames: $unknownNames,
            unparsedLines: $unparsedLines,
            salesTaxRate: $salesTax,
            brokerFeeRate: $brokerFee,
        );
    }

    private function regionOfStation(int $stationId): ?int
    {
        $hubs = config('eve.market.hubs');
        if (isset($hubs[$stationId]['region_id'])) {
            return (int) $hubs[$stationId]['region_id'];
        }

        $systemId = DB::table('stations')->where('station_id', $stationId)->value('system_id');

        return $systemId !== null
            ? (int) DB::table('solar_systems')->where('system_id', $systemId)->value('region_id')
            : null;
    }

    /**
     * @param  array<int, int>  $typeQuantities
     * @param  array<int, array{buy: float, sell: float}>  $priceMap
     * @return array<int, true> the highest-value type ids, as a lookup set
     */
    private function highestValueTypes(array $typeQuantities, array $priceMap): array
    {
        $values = [];
        foreach ($typeQuantities as $typeId => $quantity) {
            $values[$typeId] = ($priceMap[$typeId]['sell'] ?? 0.0) * $quantity;
        }

        arsort($values);

        return array_fill_keys(array_slice(array_keys($values), 0, self::HISTORY_LOOKUP_CAP), true);
    }
}
