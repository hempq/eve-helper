<?php

namespace App\Services\Market;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares the character's open market orders against the current best price
 * at the same station and advises whether relisting is worth its fee.
 */
class UndercutService
{
    /** Cache of fetched structure order books within one check() call. */
    private array $structureBooks = [];

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly TradeFeeService $fees,
    ) {}

    /**
     * @return Collection<int, object> one row per open order, worst first
     */
    public function check(Character $character): Collection
    {
        $orders = DB::table('character_orders as o')
            ->leftJoin('item_types as t', 't.type_id', '=', 'o.type_id')
            ->where('o.character_id', $character->character_id)
            ->get(['o.*', 't.name']);

        if ($orders->isEmpty()) {
            return collect();
        }

        $brokerFee = $this->fees->brokerFeeRate($character);
        $this->structureBooks = [];

        return $orders
            ->map(function ($order) use ($character, $brokerFee) {
                $isStructure = $order->location_id > 1_000_000_000_000;

                $best = $isStructure
                    ? $this->bestStructurePrice(
                        $character,
                        (int) $order->location_id,
                        (int) $order->type_id,
                        (bool) $order->is_buy_order,
                        (int) $order->order_id,
                    )
                    : $this->bestCompetingPrice(
                        (int) $order->region_id,
                        (int) $order->type_id,
                        (int) $order->location_id,
                        (bool) $order->is_buy_order,
                        (int) $order->order_id,
                    );

                $isBuy = (bool) $order->is_buy_order;
                $price = (float) $order->price;

                $undercut = $best !== null && ($isBuy ? $best > $price : $best < $price);

                // Value lost if the order fills at your price... it won't:
                // an undercut order simply doesn't sell. Compare updating:
                // match the best price and pay the relist fee (broker fee on
                // the remaining order value, conservative estimate).
                $matchPrice = $undercut ? $best : null;
                $relistCost = $matchPrice !== null
                    ? $brokerFee * $matchPrice * (int) $order->volume_remain
                    : null;
                $lossIfMatched = $matchPrice !== null
                    ? abs($price - $matchPrice) * (int) $order->volume_remain
                    : null;

                return (object) [
                    'orderId' => (int) $order->order_id,
                    'name' => $order->name ?? 'Type #'.$order->type_id,
                    'isBuy' => $isBuy,
                    'price' => $price,
                    'bestPrice' => $best,
                    'undercut' => $undercut,
                    'unknown' => $best === null,
                    'isStructure' => $isStructure,
                    'volumeRemain' => (int) $order->volume_remain,
                    'volumeTotal' => (int) $order->volume_total,
                    'locationName' => $this->locationName((int) $order->location_id),
                    'relistCost' => $relistCost,
                    'lossIfMatched' => $lossIfMatched,
                    'worthUpdating' => $undercut && $relistCost !== null
                        && $matchPrice * (int) $order->volume_remain * 0.05 > $relistCost,
                ];
            })
            ->sortByDesc(fn ($row) => [$row->undercut, $row->price * $row->volumeRemain])
            ->values();
    }

    private function bestCompetingPrice(int $regionId, int $typeId, int $locationId, bool $isBuy, int $ownOrderId): ?float
    {
        try {
            $orders = $this->esi->get("/markets/{$regionId}/orders", [
                'type_id' => $typeId,
                'order_type' => $isBuy ? 'buy' : 'sell',
            ])->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return null;
        }

        $prices = collect($orders)
            ->filter(fn (array $o) => (int) $o['location_id'] === $locationId
                && (int) $o['order_id'] !== $ownOrderId)
            ->pluck('price');

        if ($prices->isEmpty()) {
            return null;
        }

        return $isBuy ? (float) $prices->max() : (float) $prices->min();
    }

    /**
     * Best competing price in an Upwell structure. The structure market
     * endpoint isn't filterable by type, so the whole (paginated) book is
     * pulled once per structure per check() and reused. Needs docking access
     * (scope esi-markets.structure_markets.v1); on failure returns null.
     */
    private function bestStructurePrice(Character $character, int $structureId, int $typeId, bool $isBuy, int $ownOrderId): ?float
    {
        if (! array_key_exists($structureId, $this->structureBooks)) {
            try {
                $this->structureBooks[$structureId] = $this->esi->getAllPages(
                    "/markets/structures/{$structureId}", [], $character,
                );
            } catch (EsiErrorLimited|EsiRequestFailed) {
                $this->structureBooks[$structureId] = null;
            }
        }

        $book = $this->structureBooks[$structureId];
        if ($book === null) {
            return null;
        }

        $prices = collect($book)
            ->filter(fn (array $o) => (int) $o['type_id'] === $typeId
                && (bool) ($o['is_buy_order'] ?? false) === $isBuy
                && (int) $o['order_id'] !== $ownOrderId)
            ->pluck('price');

        if ($prices->isEmpty()) {
            return null;
        }

        return $isBuy ? (float) $prices->max() : (float) $prices->min();
    }

    private function locationName(int $locationId): string
    {
        return DB::table('stations')->where('station_id', $locationId)->value('name')
            ?? "Structure #{$locationId}";
    }
}
