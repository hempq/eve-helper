<?php

namespace App\Services\Market;

/**
 * Market prices with a contract-ask fallback: deadspace/faction loot often
 * has no order-book price at all, which silently zeroed it out of appraisals
 * and the net-worth total. Where Fuzzwork has nothing, the type's public
 * single-item contract ask (20th percentile) stands in for both sides —
 * an asking price, so still conservative for a seller.
 */
class CompositePriceProvider implements PriceProviderInterface
{
    public function __construct(
        private readonly PriceProviderInterface $market,
        private readonly ContractPriceService $contracts,
    ) {}

    public function prices(int $stationId, array $typeIds): array
    {
        $prices = $this->market->prices($stationId, $typeIds);

        $missing = array_values(array_filter(
            $typeIds,
            fn (int $typeId) => ! isset($prices[$typeId])
                || (($prices[$typeId]['buy'] ?? 0.0) <= 0.0 && ($prices[$typeId]['sell'] ?? 0.0) <= 0.0),
        ));

        foreach ($this->contracts->prices($missing) as $typeId => $contract) {
            $prices[$typeId] = ['buy' => $contract->p20Price, 'sell' => $contract->p20Price];
        }

        return $prices;
    }
}
