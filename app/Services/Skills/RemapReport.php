<?php

namespace App\Services\Skills;

use Carbon\CarbonImmutable;

final readonly class RemapReport
{
    public function __construct(
        public int $totalSp,
        public int $skillCount,
        /** @var list<TrainingBucket> */
        public array $buckets,
        public AttributeSet $currentAttributes,
        public AttributeSet $implantBonuses,
        public AttributeSet $currentBase,
        public AttributeSet $optimalBase,
        public float $currentMinutes,
        public float $optimalMinutes,
        public int $bonusRemaps,
        public ?CarbonImmutable $nextYearlyRemapAt,
    ) {}

    public function optimalAttributes(): AttributeSet
    {
        return $this->optimalBase->add($this->implantBonuses);
    }

    public function savedMinutes(): float
    {
        return max(0, $this->currentMinutes - $this->optimalMinutes);
    }

    public function canRemapNow(): bool
    {
        return $this->bonusRemaps > 0
            || $this->nextYearlyRemapAt === null
            || $this->nextYearlyRemapAt->isPast();
    }
}
