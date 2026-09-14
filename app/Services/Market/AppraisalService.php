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
        $resolved = [];

        foreach ($parsed['items'] as $name => $quantity) {
            $type = $types->get(mb_strtolower($name));

            if ($type === null) {
                $unknown[] = $name;

                continue;
            }

            $resolved[] = ['type' => $type, 'quantity' => $quantity];
        }

        $priceMap = $resolved === [] ? [] : $this->prices->prices(
            $stationId,
            array_map(fn ($r) => (int) $r['type']->type_id, $resolved),
        );

        $salesTax = $this->fees->salesTaxRate($character);
        $brokerFee = $this->fees->brokerFeeRate($character);

        $items = [];

        foreach ($resolved as $row) {
            $typeId = (int) $row['type']->type_id;
            $buy = $priceMap[$typeId]['buy'] ?? 0.0;
            $sell = $priceMap[$typeId]['sell'] ?? 0.0;
            $quantity = $row['quantity'];

            $items[] = new AppraisalItem(
                typeId: $typeId,
                name: $row['type']->name,
                quantity: $quantity,
                volume: $row['type']->volume !== null ? (float) $row['type']->volume : null,
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
            unknownNames: $unknown,
            unparsedLines: $parsed['unparsed'],
            salesTaxRate: $salesTax,
            brokerFeeRate: $brokerFee,
        );
    }
}
