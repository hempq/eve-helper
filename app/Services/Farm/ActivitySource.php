<?php

namespace App\Services\Farm;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;

/**
 * Thin wrapper over the two public ESI activity endpoints, shared by the
 * recorder (which stores snapshots) and the scorer (which reads them).
 */
class ActivitySource
{
    public function __construct(private readonly EsiClientInterface $esi) {}

    /**
     * @return array{0: array<int,int>, 1: array<int,int>, 2: array<int,int>}
     *   npc kills, ship kills, pod kills by system id
     */
    public function killActivity(): array
    {
        try {
            $rows = $this->esi->get('/universe/system_kills')->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [[], [], []];
        }

        $npc = $ship = $pod = [];

        foreach ($rows as $row) {
            $id = (int) $row['system_id'];
            $npc[$id] = (int) ($row['npc_kills'] ?? 0);
            $ship[$id] = (int) ($row['ship_kills'] ?? 0);
            $pod[$id] = (int) ($row['pod_kills'] ?? 0);
        }

        return [$npc, $ship, $pod];
    }

    /**
     * @return array<int, int> gate transits by system id
     */
    public function jumpActivity(): array
    {
        try {
            $rows = $this->esi->get('/universe/system_jumps')->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [];
        }

        $traffic = [];

        foreach ($rows as $row) {
            $traffic[(int) $row['system_id']] = (int) ($row['ship_jumps'] ?? 0);
        }

        return $traffic;
    }
}
