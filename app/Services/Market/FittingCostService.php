<?php

namespace App\Services\Market;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replacement cost of the character's saved fittings, priced at the Jita
 * sell percentile (what buying it all back would cost right now).
 * Needs esi-fittings.read_fittings.v1 (re-login to grant).
 */
class FittingCostService
{
    private const JITA = 60003760;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly PriceProviderInterface $prices,
    ) {}

    /**
     * @return object{needsScope: bool, fittings: Collection<int, object>}
     */
    public function replacementCosts(Character $character): object
    {
        try {
            $rows = $this->esi->get("/characters/{$character->character_id}/fittings", [], $character)->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['needsScope' => true, 'fittings' => collect()];
        }

        if ($rows === []) {
            return (object) ['needsScope' => false, 'fittings' => collect()];
        }

        $typeIds = [];
        foreach ($rows as $fitting) {
            $typeIds[] = (int) $fitting['ship_type_id'];
            foreach ($fitting['items'] ?? [] as $item) {
                $typeIds[] = (int) $item['type_id'];
            }
        }
        $priceMap = $this->prices->prices(self::JITA, array_values(array_unique($typeIds)));
        $shipNames = DB::table('item_types')
            ->whereIn('type_id', array_map(fn ($f) => (int) $f['ship_type_id'], $rows))
            ->pluck('name', 'type_id');

        $fittings = collect($rows)->map(function (array $fitting) use ($priceMap, $shipNames) {
            $shipTypeId = (int) $fitting['ship_type_id'];
            $hullCost = $priceMap[$shipTypeId]['sell'] ?? 0.0;

            $fittingsCost = 0.0;
            $unpriced = 0;
            foreach ($fitting['items'] ?? [] as $item) {
                $price = $priceMap[(int) $item['type_id']]['sell'] ?? null;
                if ($price === null) {
                    $unpriced++;

                    continue;
                }
                $fittingsCost += $price * (int) ($item['quantity'] ?? 1);
            }

            return (object) [
                'name' => (string) ($fitting['name'] ?? '?'),
                'ship' => $shipNames[$shipTypeId] ?? ('Type #'.$shipTypeId),
                'hullCost' => $hullCost,
                'fittingsCost' => $fittingsCost,
                'total' => $hullCost + $fittingsCost,
                'unpriced' => $unpriced,
            ];
        })->sortByDesc('total')->values();

        return (object) ['needsScope' => false, 'fittings' => $fittings];
    }
}
