<?php

namespace App\Services\Skills;

final readonly class PlanEntry
{
    public function __construct(
        public int $skillId,
        public string $name,
        public int $level,
        public int $sp,
        public int $rank,
        public string $primaryAttribute,
        public string $secondaryAttribute,
        public bool $isPrerequisite,
    ) {}
}
