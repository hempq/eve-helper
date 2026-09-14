<?php

namespace App\Services\Market;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;

/**
 * Prices from Fuzzwork's station aggregates (refreshed ~every 30 minutes).
 * Kept behind PriceProviderInterface: third-party EVE price services have a
 * history of dying, so the provider must stay swappable.
 */
class FuzzworkPriceProvider implements PriceProviderInterface
{
    private const CHUNK = 100;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly int $cacheSeconds,
    ) {}

    public function prices(int $stationId, array $typeIds): array
    {
        $typeIds = array_values(array_unique($typeIds));
        $prices = [];
        $missing = [];

        foreach ($typeIds as $typeId) {
            $cached = $this->cache->get($this->cacheKey($stationId, $typeId));

            if ($cached !== null) {
                $prices[$typeId] = $cached;
            } else {
                $missing[] = $typeId;
            }
        }

        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach ($this->fetch($stationId, $chunk) as $typeId => $price) {
                $prices[$typeId] = $price;
                $this->cache->put($this->cacheKey($stationId, $typeId), $price, $this->cacheSeconds);
            }
        }

        return $prices;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, array{buy: float, sell: float}>
     */
    private function fetch(int $stationId, array $typeIds): array
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->timeout(30)
            ->get($this->baseUrl, [
                'station' => $stationId,
                'types' => implode(',', $typeIds),
            ])
            ->throw()
            ->json();

        $prices = [];

        foreach ($response ?? [] as $typeId => $aggregate) {
            $buy = (float) ($aggregate['buy']['percentile'] ?? 0);
            $sell = (float) ($aggregate['sell']['percentile'] ?? 0);

            if ($buy > 0.0 || $sell > 0.0) {
                $prices[(int) $typeId] = ['buy' => $buy, 'sell' => $sell];
            }
        }

        return $prices;
    }

    private function cacheKey(int $stationId, int $typeId): string
    {
        return "market:fuzzwork:{$stationId}:{$typeId}";
    }
}
