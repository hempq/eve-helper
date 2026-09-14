<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Facades\DB;

class AppraisalService
{
    public function __construct(
        private readonly PasteParser $parser,
        private readonly PriceProviderInterface $prices,
        private readonly TradeFeeService $fees,
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

        $items = [];

        foreach ($typeQuantities as $typeId => $quantity) {
            $type = $types->get($typeId);

            if ($type === null) {
                continue;
            }

            $buy = $priceMap[$typeId]['buy'] ?? 0.0;
            $sell = $priceMap[$typeId]['sell'] ?? 0.0;

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
            );
        }

        usort($items, fn (AppraisalItem $a, AppraisalItem $b) => $b->orderNet <=> $a->orderNet);

        return new AppraisalResult(
            stationId: $stationId,
            items: $items,
            unknownNames: $unknownNames,
            unparsedLines: $unparsedLines,
            salesTaxRate: $salesTax,
            brokerFeeRate: $brokerFee,
        );
    }
}
