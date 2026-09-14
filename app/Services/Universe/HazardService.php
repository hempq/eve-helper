<?php

namespace App\Services\Universe;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Farm\ActivitySource;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Per-system extra route costs from live hazards: incursion constellations
 * (buffed NPCs, replaced sites), contested faction-warfare systems (gate
 * camps, roams) and systems with player kills in the last hour. Soft
 * penalties, not hard bans — a leg only detours when the hazard costs more
 * jumps than the way around it, which naturally caps detour length.
 */
class HazardService
{
    private const CACHE_KEY = 'hazard:costs';

    private const CACHE_SECONDS = 600;

    private const INCURSION_COST = 40.0;

    private const FW_CONTESTED_COST = 8.0;

    private const COST_PER_KILL = 1.5;

    private const MAX_KILL_COST = 30.0;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly ActivitySource $activity,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array<int, float> system id => extra jump-equivalent cost
     */
    public function costs(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $costs = [];

            try {
                foreach ($this->esi->get('/incursions/')->data as $incursion) {
                    foreach ($incursion['infested_solar_systems'] ?? [] as $systemId) {
                        $costs[(int) $systemId] = self::INCURSION_COST;
                    }
                }
            } catch (EsiErrorLimited|EsiRequestFailed) {
                // no incursion data — skip the layer
            }

            try {
                foreach ($this->esi->get('/fw/systems/')->data as $row) {
                    if (($row['contested'] ?? '') !== 'uncontested') {
                        $id = (int) $row['solar_system_id'];
                        $costs[$id] = max($costs[$id] ?? 0.0, self::FW_CONTESTED_COST);
                    }
                }
            } catch (EsiErrorLimited|EsiRequestFailed) {
                // no FW data — skip the layer
            }

            try {
                [, $shipKills, $podKills] = $this->activity->killActivity();
                foreach ($shipKills as $id => $ships) {
                    $kills = $ships + ($podKills[$id] ?? 0);
                    if ($kills > 0) {
                        $cost = min(self::MAX_KILL_COST, $kills * self::COST_PER_KILL);
                        $costs[(int) $id] = max($costs[(int) $id] ?? 0.0, $cost);
                    }
                }
            } catch (\Throwable) {
                // kill snapshot unavailable — skip the layer
            }

            return $costs;
        });
    }
}
