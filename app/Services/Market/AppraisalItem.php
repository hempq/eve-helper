<?php

namespace App\Services\Market;

final readonly class AppraisalItem
{
    public function __construct(
        public int $typeId,
        public string $name,
        public int $quantity,
        public ?float $volume,
        public float $buyPrice,
        public float $sellPrice,
        public float $instantNet,
        public float $orderNet,
        public ?float $avgDailyVolume = null,
        /** @var ?object{sampleCount: int, minPrice: float, p20Price: float, medianPrice: float} */
        public ?object $contractPrice = null,
        /** Units already listed in sell orders at this hub (the queue ahead of you). */
        public ?int $queueAhead = null,
        /** @var ?list<float> 30-day daily average price, for the sparkline */
        public ?array $priceSeries = null,
    ) {}

    /**
     * Days to sell the stack as a market order. With scanned order-book
     * depth the whole standing sell queue is assumed to clear before your
     * listing does (an upper bound); without it, just your own stack against
     * the daily volume (optimistic). Null when there is no volume data.
     */
    public function daysToSell(): ?float
    {
        if ($this->avgDailyVolume === null || $this->avgDailyVolume <= 0) {
            return null;
        }

        return (($this->queueAhead ?? 0) + $this->quantity) / $this->avgDailyVolume;
    }

    public function recommendation(): string
    {
        if ($this->buyPrice <= 0 && $this->sellPrice <= 0) {
            return 'no-market';
        }

        // Illiquid items (deadspace / faction loot) barely trade on the
        // market — a contract reaches buyers better and skips broker fees.
        if ($this->avgDailyVolume !== null && $this->avgDailyVolume < 1) {
            return 'contract';
        }

        return $this->orderNet > $this->instantNet * 1.05 ? 'sell-order' : 'instant';
    }
}
