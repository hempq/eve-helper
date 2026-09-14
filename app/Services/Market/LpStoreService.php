<?php

namespace App\Services\Market;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks LP-store offers by ISK per loyalty point for the corporations the
 * character has LP with. Prices come from the Jita order book (5% percentile
 * buy AND sell): an offer is ranked by its net sell-order proceeds (tax +
 * broker fee at the pilot's skills), with the instant-sell (hit buy orders)
 * ISK/LP alongside — an offer that looks great at the sell price but never
 * trades is a trap, so daily traded volume and fill time are attached too.
 * Needs esi-characters.read_loyalty.v1 (a re-login may be required to grant).
 */
class LpStoreService
{
    private const JITA = 60003760;

    private const JITA_REGION = 10000002;

    /** Volume lookups are cached-daily ESI calls; only the top offers get one. */
    private const VOLUME_LOOKUP_CAP = 30;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly PriceProviderInterface $prices,
        private readonly TradeFeeService $fees,
        private readonly MarketHistoryService $history,
    ) {}

    /**
     * @return array{needsScope: bool, offers: Collection<int, object>}
     */
    public function bestOffers(Character $character, int $limit = 40): array
    {
        try {
            $loyalty = $this->esi->get("/characters/{$character->character_id}/loyalty/points", [], $character)->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return ['needsScope' => true, 'offers' => collect()];
        }

        $lpByCorp = [];
        foreach ($loyalty as $row) {
            $lpByCorp[(int) $row['corporation_id']] = (int) $row['loyalty_points'];
        }

        if ($lpByCorp === []) {
            return ['needsScope' => false, 'offers' => collect()];
        }

        $offers = [];
        foreach ($lpByCorp as $corpId => $points) {
            try {
                $corpOffers = $this->esi->get("/loyalty/stores/{$corpId}/offers")->data;
            } catch (EsiErrorLimited|EsiRequestFailed) {
                continue;
            }
            foreach ($corpOffers as $offer) {
                $offer['corporation_id'] = $corpId;
                $offer['available_lp'] = $points;
                $offers[] = $offer;
            }
        }

        if ($offers === []) {
            return ['needsScope' => false, 'offers' => collect()];
        }

        $typeIds = [];
        foreach ($offers as $offer) {
            $typeIds[] = (int) $offer['type_id'];
            foreach ($offer['required_items'] ?? [] as $req) {
                $typeIds[] = (int) $req['type_id'];
            }
        }
        $prices = $this->prices->prices(self::JITA, array_values(array_unique($typeIds)));
        $names = DB::table('item_types')->whereIn('type_id', array_unique($typeIds))->pluck('name', 'type_id');
        $corpNames = DB::table('item_types')->whereIn('type_id', array_keys($lpByCorp))->pluck('name', 'type_id');

        $salesTax = $this->fees->salesTaxRate($character);
        $brokerFee = $this->fees->brokerFeeRate($character);

        $ranked = collect($offers)->map(function (array $offer) use ($prices, $names, $corpNames, $salesTax, $brokerFee) {
            $typeId = (int) $offer['type_id'];
            $quantity = (int) $offer['quantity'];
            $sell = $prices[$typeId]['sell'] ?? 0.0;
            $buy = $prices[$typeId]['buy'] ?? 0.0;

            // Required items are bought off sell orders at full price.
            $reqCost = 0.0;
            foreach ($offer['required_items'] ?? [] as $req) {
                $reqCost += ($prices[(int) $req['type_id']]['sell'] ?? 0.0) * (int) $req['quantity'];
            }

            $lpCost = (int) ($offer['lp_cost'] ?? 0);
            $baseCost = (float) ($offer['isk_cost'] ?? 0) + $reqCost;

            // Listing a sell order pays tax + broker; hitting buy orders
            // pays tax only but at the (lower) buy price.
            $sellNet = $sell * $quantity * (1 - $salesTax - $brokerFee);
            $instantNet = $buy * $quantity * (1 - $salesTax);

            $profitSell = $sellNet - $baseCost;
            $profitInstant = $instantNet - $baseCost;

            return (object) [
                'typeId' => $typeId,
                'item' => $names[$typeId] ?? ('Type #'.$typeId),
                'quantity' => $quantity,
                'corp' => $corpNames[$offer['corporation_id']] ?? ('Corp #'.$offer['corporation_id']),
                'lpCost' => $lpCost,
                'iskCost' => (float) ($offer['isk_cost'] ?? 0),
                'sellPrice' => $sell,
                'buyPrice' => $buy,
                'profit' => $profitSell,
                'iskPerLp' => $lpCost > 0 ? $profitSell / $lpCost : 0.0,
                'iskPerLpInstant' => $lpCost > 0 ? $profitInstant / $lpCost : 0.0,
                'affordable' => $lpCost <= $offer['available_lp'],
                'dailyVolume' => null,
            ];
        })
            ->filter(fn ($o) => $o->iskPerLp > 0 || $o->iskPerLpInstant > 0)
            ->sortByDesc('iskPerLp')
            ->take($limit)
            ->values();

        $this->attachVolumes($ranked);

        return ['needsScope' => false, 'offers' => $ranked];
    }

    /**
     * Daily traded Jita volume for the top offers — the "looks profitable
     * but never actually sells" detector.
     */
    private function attachVolumes(Collection $ranked): void
    {
        if (! config('eve.market.history_enabled')) {
            return;
        }

        foreach ($ranked->take(self::VOLUME_LOOKUP_CAP) as $offer) {
            $offer->dailyVolume = $this->history->averageDailyVolume(self::JITA_REGION, $offer->typeId);
        }
    }
}
