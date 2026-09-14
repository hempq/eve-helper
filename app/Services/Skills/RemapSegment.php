<?php

namespace App\Services\Skills;

final readonly class RemapSegment
{
    public function __construct(
        /** @var list<TrainingBucket> */
        public array $buckets,
        public string $dominantPrimary,
        public int $sp,
        public AttributeSet $base,
        public float $minutes,
        /** Start offset on the optimal timeline (minutes from plan start). */
        public float $startMinutes,
    ) {}
}
