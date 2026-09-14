<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups the character's synced assets by their physical root location.
 * Assets inside containers/ships point at the parent item; the chain is
 * walked up until a station/structure/solar system is reached.
 */
class AssetLocationService
{
    /**
     * @return Collection<int, object{location_id: int, location_type: string,
     *   name: string, itemCount: int, typeQuantities: array<int, int>}>
     *   ordered by item count, typeQuantities only covers marketable types
     */
    public function locations(Character $character): Collection
    {
        $assets = DB::table('character_assets as a')
            ->leftJoin('item_types as t', 't.type_id', '=', 'a.type_id')
            ->where('a.character_id', $character->character_id)
            ->get(['a.item_id', 'a.type_id', 'a.quantity', 'a.location_id', 'a.location_type', 't.market_group_id']);

        $byItemId = $assets->keyBy('item_id');

        $groups = [];

        foreach ($assets as $asset) {
            [$rootId, $rootType] = $this->rootOf($asset, $byItemId);

            $group = $groups[$rootId] ??= (object) [
                'location_id' => $rootId,
                'location_type' => $rootType,
                'name' => null,
                'itemCount' => 0,
                'typeQuantities' => [],
            ];

            $group->itemCount++;

            if ($asset->market_group_id !== null) {
                $group->typeQuantities[$asset->type_id] =
                    ($group->typeQuantities[$asset->type_id] ?? 0) + (int) $asset->quantity;
            }
        }

        $this->attachNames($groups);

        return collect($groups)->sortByDesc('itemCount')->values();
    }

    /**
     * @param  Collection<int, object>  $byItemId
     * @return array{0: int, 1: string}
     */
    private function rootOf(object $asset, Collection $byItemId): array
    {
        $current = $asset;
        $hops = 0;

        while ($current->location_type === 'item' && $hops < 10) {
            $parent = $byItemId->get($current->location_id);

            if ($parent === null) {
                break; // parent not in our assets (e.g. corp container)
            }

            $current = $parent;
            $hops++;
        }

        return [(int) $current->location_id, (string) $current->location_type];
    }

    /**
     * @param  array<int, object>  $groups
     */
    private function attachNames(array $groups): void
    {
        $ids = array_keys($groups);

        $stations = DB::table('stations')->whereIn('station_id', $ids)->pluck('name', 'station_id');
        $systems = DB::table('solar_systems')->whereIn('system_id', $ids)->pluck('name', 'system_id');

        foreach ($groups as $id => $group) {
            $group->name = $stations[$id]
                ?? ($systems[$id] ?? null ? $systems[$id].' (in space)' : null)
                ?? ($group->location_type === 'structure' ? "Player structure #{$id}" : "Location #{$id}");
        }
    }
}
