<?php

namespace App\Services\Esi;

use Carbon\CarbonImmutable;

final readonly class EsiResponse
{
    public function __construct(
        public array $data,
        public CarbonImmutable $expiresAt,
        public int $pages = 1,
        public bool $fromCache = false,
    ) {}
}
