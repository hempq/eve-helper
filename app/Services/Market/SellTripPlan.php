<?php

namespace App\Services\Market;

final readonly class SellTripPlan
{
    /**
     * @param  list<TripStop>  $stops  in visiting order
     * @param  list<string>  $unsellableNames  no positive price at any hub
     */
    public function __construct(
        public array $stops,
        public float $totalNet,
        public ?int $totalJumps,
        public float $singleHubNet,
        public ?int $singleHubJumps,
        public string $singleHubName,
        public string $metric,
        public array $unsellableNames,
    ) {}

    public function extraOverSingleHub(): float
    {
        return max(0, $this->totalNet - $this->singleHubNet);
    }

    public function extraJumps(): ?int
    {
        if ($this->totalJumps === null || $this->singleHubJumps === null) {
            return null;
        }

        return max(0, $this->totalJumps - $this->singleHubJumps);
    }
}
