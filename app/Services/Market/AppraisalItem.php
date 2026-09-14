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
    ) {}

    /**
     * Optimistic days to sell the stack as a market order (you'd capture at
     * most the whole daily volume; realistically less). Null when there is no
     * volume data.
     */
    public function daysToSell(): ?float
    {
        if ($this->avgDailyVolume === null || $this->avgDailyVolume <= 0) {
            return null;
        }

        return $this->quantity / $this->avgDailyVolume;
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
