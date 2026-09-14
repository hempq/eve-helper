<?php

namespace App\Services\Skills;

final readonly class MultiRemapPlan
{
    public function __construct(
        /** @var list<RemapSegment> */
        public array $segments,
        public float $totalMinutes,
        public float $singleRemapMinutes,
    ) {}

    /** Time saved versus training the same sequence under one optimal remap. */
    public function savedMinutes(): float
    {
        return max(0.0, $this->singleRemapMinutes - $this->totalMinutes);
    }

    public function remapCount(): int
    {
        return count($this->segments);
    }
}
