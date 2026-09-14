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
    ) {}

    public function recommendation(): string
    {
        if ($this->buyPrice <= 0 && $this->sellPrice <= 0) {
            return 'no-market';
        }

        return $this->orderNet > $this->instantNet * 1.05 ? 'sell-order' : 'instant';
    }
}
