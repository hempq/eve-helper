<?php

namespace App\Services\Skills;

final readonly class RemapResult
{
    public function __construct(
        public AttributeSet $baseAttributes,
        public float $minutes,
    ) {}
}
