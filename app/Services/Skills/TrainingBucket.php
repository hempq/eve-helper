<?php

namespace App\Services\Skills;

/**
 * SP to be trained under one primary/secondary attribute pair — the unit the
 * remap optimizer works on (aggregating a plan by pair keeps the brute-force
 * search tiny).
 */
final readonly class TrainingBucket
{
    public function __construct(
        public string $primaryAttribute,
        public string $secondaryAttribute,
        public int $sp,
    ) {}
}
