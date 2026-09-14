<?php

namespace App\Services\Market;

interface PriceProviderInterface
{
    /**
     * 5%-percentile buy/sell prices at a station for the given types.
     * Percentile prices resist 0.01-ISK scam orders, per community practice.
     *
     * @param  list<int>  $typeIds
     * @return array<int, array{buy: float, sell: float}> keyed by type id;
     *         types with no market data are omitted
     */
    public function prices(int $stationId, array $typeIds): array;
}
