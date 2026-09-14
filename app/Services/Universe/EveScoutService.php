<?php

namespace App\Services\Universe;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live Thera/Turnur wormhole connections scanned by EVE-Scout (Signal
 * Cartel). These act as temporary extra edges on the stargate graph.
 */
class EveScoutService
{
    private const CACHE_KEY = 'evescout:connections';

    private const CACHE_SECONDS = 900;

    public const THERA_SYSTEM_ID = 31000005;

    public const TURNUR_SYSTEM_ID = 30002086;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $userAgent,
    ) {}

    /**
     * @return list<object{hubSystemId: int, hubName: string, systemId: int,
     *   systemName: string, region: ?string, remainingHours: ?float,
     *   maxShipSize: ?string}>
     */
    public function connections(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            try {
                $rows = Http::withHeaders(['User-Agent' => $this->userAgent])
                    ->timeout(15)
                    ->get('https://api.eve-scout.com/v2/public/signatures')
                    ->throw()
                    ->json();
            } catch (Throwable) {
                return []; // degrade gracefully when EVE-Scout is down
            }

            $connections = [];

            foreach ($rows ?? [] as $row) {
                if (($row['signature_type'] ?? '') !== 'wormhole' || ($row['completed'] ?? false) !== true) {
                    continue;
                }

                $hubName = $row['out_system_name'] ?? '';
                $hubSystemId = match ($hubName) {
                    'Thera' => self::THERA_SYSTEM_ID,
                    'Turnur' => self::TURNUR_SYSTEM_ID,
                    default => null,
                };

                if ($hubSystemId === null || ! isset($row['in_system_id'])) {
                    continue;
                }

                $connections[] = (object) [
                    'hubSystemId' => $hubSystemId,
                    'hubName' => $hubName,
                    'systemId' => (int) $row['in_system_id'],
                    'systemName' => $row['in_system_name'] ?? '?',
                    'region' => $row['in_region_name'] ?? null,
                    'remainingHours' => isset($row['remaining_hours']) ? (float) $row['remaining_hours'] : null,
                    'maxShipSize' => $row['max_ship_size'] ?? null,
                ];
            }

            return $connections;
        });
    }

    /**
     * The connections as bidirectional graph edges.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function edges(): array
    {
        return array_map(
            fn (object $c) => [$c->hubSystemId, $c->systemId],
            $this->connections(),
        );
    }
}
