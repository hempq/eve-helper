<?php

namespace App\Services\Market;

final readonly class TripStop
{
    /**
     * @param  list<object{typeId: int, name: string, quantity: int, net: float}>  $items
     * @param  list<int>|null  $route  system ids from the previous stop
     */
    public function __construct(
        public int $stationId,
        public string $hubName,
        public string $systemName,
        public int $systemId,
        public array $items,
        public float $net,
        public ?int $jumpsFromPrevious,
        public ?array $route,
    ) {}
}
