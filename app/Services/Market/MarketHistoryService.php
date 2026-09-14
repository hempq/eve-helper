<?php

namespace App\Services\Market;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Daily market history (volume) per type in a region, from ESI. Used to
 * estimate how liquid an item is — i.e. how long a sell order would take to
 * fill, and whether a low-liquidity item (deadspace/faction loot) is better
 * sold via contract than on the market.
 *
 * ESI market history has its own 300 req/IP/min limit, so results are cached
 * a full day (the data only refreshes once daily after downtime).
 */
class MarketHistoryService
{
    private const CACHE_SECONDS = 86400;

    private const LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly Cache $cache,
    ) {}

    /**
     * Daily average-price series over the recent window (oldest first) for
     * sparklines, or null when the type has no history. Cheap after the
     * first call: the underlying ESI response is cached a day.
     *
     * @return ?list<float>
     */
    public function dailyPriceSeries(int $regionId, int $typeId, int $days = 30): ?array
    {
        return $this->cache->remember(
            "market:history-series:{$regionId}:{$typeId}",
            self::CACHE_SECONDS,
            function () use ($regionId, $typeId, $days): ?array {
                try {
                    $rows = $this->esi->get("/markets/{$regionId}/history", ['type_id' => $typeId])->data;
                } catch (EsiErrorLimited|EsiRequestFailed) {
                    return null;
                }

                if ($rows === []) {
                    return null;
                }

                return array_values(array_map(
                    fn (array $r) => (float) ($r['average'] ?? 0),
                    array_slice($rows, -$days),
                ));
            },
        );
    }

    /**
     * Average daily traded volume over the recent window, or null when the
     * type has no market history at all (never traded here).
     */
    public function averageDailyVolume(int $regionId, int $typeId): ?float
    {
        return $this->cache->remember(
            "market:history:{$regionId}:{$typeId}",
            self::CACHE_SECONDS,
            function () use ($regionId, $typeId): ?float {
                try {
                    $rows = $this->esi->get("/markets/{$regionId}/history", ['type_id' => $typeId])->data;
                } catch (EsiErrorLimited|EsiRequestFailed) {
                    return null;
                }

                if ($rows === []) {
                    return 0.0; // known to ESI but no trades in the window
                }

                $cutoff = now()->subDays(self::LOOKBACK_DAYS)->toDateString();
                $recent = array_filter($rows, fn (array $r) => ($r['date'] ?? '') >= $cutoff);
                $window = $recent !== [] ? $recent : array_slice($rows, -self::LOOKBACK_DAYS);

                if ($window === []) {
                    return 0.0;
                }

                $volume = array_sum(array_map(fn (array $r) => (int) ($r['volume'] ?? 0), $window));

                return $volume / count($window);
            },
        );
    }
}
