<?php

namespace App\Services\Market;

use App\Services\Esi\EsiClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Full order-book scan of a hub (~50-300 region pages). Page 1 goes through
 * the caching ESI client to learn the page count; the rest are fetched in
 * parallel batches (ESI explicitly allows concurrent requests — this cuts a
 * multi-minute scan to seconds). Any page that fails in the pool is retried
 * sequentially through the ESI client, so a scan is always complete or
 * throws. Run from the eve:scan-hubs command, not from web requests.
 */
class HubPriceScanService
{
    private const POOL_SIZE = 10;

    public function __construct(private readonly EsiClientInterface $esi) {}

    /**
     * @return int number of types priced
     */
    public function scan(int $regionId, int $stationId, ?callable $onPage = null): int
    {
        $best = []; // typeId => ['bid','ask','bid_vol','ask_vol']

        $collect = function (iterable $orders) use (&$best, $stationId): void {
            foreach ($orders as $order) {
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
        };

        $first = $this->esi->get("/markets/{$regionId}/orders", ['order_type' => 'all', 'page' => 1]);
        $collect($first->data);
        $pages = $first->pages;

        if ($onPage !== null) {
            $onPage(1, $pages);
        }

        $remaining = $pages >= 2 ? range(2, $pages) : [];

        foreach (array_chunk($remaining, self::POOL_SIZE) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $page) => $pool->as((string) $page)
                    ->withHeaders([
                        'User-Agent' => config('eve.esi.user_agent'),
                        'X-Compatibility-Date' => config('eve.esi.compatibility_date'),
                        'Accept' => 'application/json',
                    ])
                    ->timeout(30)
                    ->get(config('eve.esi.base_url')."/markets/{$regionId}/orders", [
                        'order_type' => 'all',
                        'page' => $page,
                    ]),
                $batch,
            ));

            foreach ($batch as $page) {
                $response = $responses[(string) $page] ?? null;

                if ($response instanceof Response && $response->successful()) {
                    $collect($response->json() ?? []);
                } else {
                    // Fall back through the rate-limit-aware ESI client.
                    $collect($this->esi->get("/markets/{$regionId}/orders", ['order_type' => 'all', 'page' => $page])->data);
                }

                if ($onPage !== null) {
                    $onPage($page, $pages);
                }
            }
        }

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
