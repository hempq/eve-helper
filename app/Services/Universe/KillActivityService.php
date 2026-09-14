<?php

namespace App\Services\Universe;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;

/**
 * Recent player-kill activity per system from ESI (hourly snapshot) — the
 * standard proxy for gate camps and gank corridors.
 */
class KillActivityService
{
    private const DANGER_THRESHOLD = 5;

    public function __construct(private readonly EsiClientInterface $esi) {}

    /**
     * @return array<int, int> system id => ship+pod kills in the last hour;
     *         empty when ESI is unavailable (degrade gracefully)
     */
    public function playerKills(): array
    {
        try {
            $response = $this->esi->get('/universe/system_kills');
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [];
        }

        $kills = [];

        foreach ($response->data as $row) {
            $count = (int) ($row['ship_kills'] ?? 0) + (int) ($row['pod_kills'] ?? 0);

            if ($count > 0) {
                $kills[(int) $row['system_id']] = $count;
            }
        }

        return $kills;
    }

    public function isDangerous(int $kills): bool
    {
        return $kills >= self::DANGER_THRESHOLD;
    }
}
