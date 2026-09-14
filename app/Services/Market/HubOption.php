<?php

namespace App\Services\Market;

final readonly class HubOption
{
    /**
     * @param  list<int>|null  $route  system ids, origin to hub, when known
     */
    public function __construct(
        public int $stationId,
        public string $hubName,
        public string $systemName,
        public int $systemId,
        public float $orderNet,
        public float $instantNet,
        public ?int $jumps,
        public ?array $route,
    ) {}
}
