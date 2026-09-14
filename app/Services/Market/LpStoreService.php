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
 * character has LP with. Output and required items are valued at Jita.
 * Needs esi-characters.read_loyalty.v1 (a re-login may be required to grant).
 */
class LpStoreService
{
    private const JITA = 60003760;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly PriceProviderInterface $prices,
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

        $ranked = collect($offers)->map(function (array $offer) use ($prices, $names, $corpNames) {
            $outValue = ($prices[(int) $offer['type_id']]['sell'] ?? 0.0) * (int) $offer['quantity'];

            $reqCost = 0.0;
            foreach ($offer['required_items'] ?? [] as $req) {
                $reqCost += ($prices[(int) $req['type_id']]['sell'] ?? 0.0) * (int) $req['quantity'];
            }

            $lpCost = (int) ($offer['lp_cost'] ?? 0);
            $profit = $outValue - (float) ($offer['isk_cost'] ?? 0) - $reqCost;

            return (object) [
                'item' => $names[(int) $offer['type_id']] ?? ('Type #'.$offer['type_id']),
                'quantity' => (int) $offer['quantity'],
                'corp' => $corpNames[$offer['corporation_id']] ?? ('Corp #'.$offer['corporation_id']),
                'lpCost' => $lpCost,
                'iskCost' => (float) ($offer['isk_cost'] ?? 0),
                'marketValue' => $outValue,
                'profit' => $profit,
                'iskPerLp' => $lpCost > 0 ? $profit / $lpCost : 0.0,
                'affordable' => $lpCost <= $offer['available_lp'],
            ];
        })
            ->filter(fn ($o) => $o->iskPerLp > 0)
            ->sortByDesc('iskPerLp')
            ->take($limit)
            ->values();

        return ['needsScope' => false, 'offers' => $ranked];
    }
}
