<?php

namespace App\Services\Market;

final readonly class AppraisalResult
{
    /**
     * @param  list<AppraisalItem>  $items
     * @param  list<string>  $unknownNames  parsed but not found in the SDE
     * @param  list<string>  $unparsedLines
     */
    public function __construct(
        public int $stationId,
        public array $items,
        public array $unknownNames,
        public array $unparsedLines,
        public float $salesTaxRate,
        public float $brokerFeeRate,
    ) {}

    public function totalInstantNet(): float
    {
        return array_sum(array_map(fn (AppraisalItem $i) => $i->instantNet, $this->items));
    }

    public function totalOrderNet(): float
    {
        return array_sum(array_map(fn (AppraisalItem $i) => $i->orderNet, $this->items));
    }

    public function totalVolume(): float
    {
        return array_sum(array_map(fn (AppraisalItem $i) => ($i->volume ?? 0) * $i->quantity, $this->items));
    }
}
