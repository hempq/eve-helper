<?php

namespace App\Services\Market;

use App\Services\Esi\EsiClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Full order-book scan of a hub: streams the region's order pages from ESI
 * (ETag-cached page by page), keeps only orders sitting at the hub station,
 * and stores best bid/ask with depth per type. Heavy (~50-300 pages per
 * region) — run from the eve:scan-hubs command, not from web requests.
 */
class HubPriceScanService
{
    public function __construct(private readonly EsiClientInterface $esi) {}

    /**
     * @return int number of types priced
     */
    public function scan(int $regionId, int $stationId, ?callable $onPage = null): int
    {
        $best = []; // typeId => ['bid','ask','bid_vol','ask_vol']

        $page = 1;
        $pages = 1;

        do {
            $response = $this->esi->get("/markets/{$regionId}/orders", [
                'order_type' => 'all',
                'page' => $page,
            ]);
            $pages = $response->pages;

            if ($onPage !== null) {
                $onPage($page, $pages);
            }

            foreach ($response->data as $order) {
                if ((int) $order['location_id'] !== $stationId) {
                    continue;
                }

                $typeId = (int) $order['type_id'];
                $price = (float) $order['price'];
                $volume = (int) $order['volume_remain'];

                $entry = &$best[$typeId];
                $entry ??= ['bid' => null, 'ask' => null, 'bid_vol' => 0, 'ask_vol' => 0];

                if ($order['is_buy_order'] ?? false) {
                    $entry['bid'] = max($entry['bid'] ?? 0.0, $price);
                    $entry['bid_vol'] += $volume;
                } else {
                    $entry['ask'] = $entry['ask'] === null ? $price : min($entry['ask'], $price);
                    $entry['ask_vol'] += $volume;
                }
                unset($entry);
            }

            $page++;
        } while ($page <= $pages);

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($best as $typeId => $entry) {
            $rows[] = [
                'station_id' => $stationId,
                'type_id' => $typeId,
                'best_bid' => $entry['bid'],
                'best_ask' => $entry['ask'],
                'bid_volume' => $entry['bid_vol'],
                'ask_volume' => $entry['ask_vol'],
                'scanned_at' => $now,
            ];
        }

        DB::transaction(function () use ($stationId, $rows) {
            DB::table('hub_prices')->where('station_id', $stationId)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('hub_prices')->insert($chunk);
            }
        });

        return count($rows);
    }
}
