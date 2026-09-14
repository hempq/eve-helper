<?php

namespace App\Services\Universe;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Support\Facades\DB;

/**
 * Live incursion and faction-warfare state (both public ESI, no scope).
 * Deliberately separate from the farm scorer: these are their own overlays —
 * places to avoid while farming, or to go for LP — not another score term.
 */
class WarzoneService
{
    private const FACTIONS = [
        500001 => 'Caldari State',
        500002 => 'Minmatar Republic',
        500003 => 'Amarr Empire',
        500004 => 'Gallente Federation',
        500010 => 'Guristas Pirates',
        500011 => 'Angel Cartel',
        500019 => "Sansha's Nation",
        500026 => 'Triglavian Collective',
        500027 => 'EDENCOM',
    ];

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly RouteService $routes,
    ) {}

    public function factionName(int $factionId): string
    {
        return self::FACTIONS[$factionId] ?? "Faction #{$factionId}";
    }

    /**
     * Active incursions, closest staging first.
     *
     * @return list<object{constellation: string, region: string, state: string,
     *   faction: string, influence: float, hasBoss: bool, systemCount: int,
     *   staging: string, stagingSecurity: ?float, distance: ?int}>
     */
    public function incursions(?int $originSystemId = null): array
    {
        try {
            $rows = $this->esi->get('/incursions/')->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [];
        }

        $distances = $this->distances($originSystemId);

        $constellationIds = array_map(fn ($r) => (int) $r['constellation_id'], $rows);
        $constellations = DB::table('constellations as c')
            ->join('regions as r', 'r.region_id', '=', 'c.region_id')
            ->whereIn('c.constellation_id', $constellationIds)
            ->get(['c.constellation_id', 'c.name', 'r.name as region'])
            ->keyBy('constellation_id');

        $stagingIds = array_map(fn ($r) => (int) ($r['staging_solar_system_id'] ?? 0), $rows);
        $systems = DB::table('solar_systems')
            ->whereIn('system_id', $stagingIds)
            ->get(['system_id', 'name', 'security'])
            ->keyBy('system_id');

        $incursions = array_map(function (array $row) use ($constellations, $systems, $distances) {
            $constellation = $constellations->get((int) $row['constellation_id']);
            $staging = $systems->get((int) ($row['staging_solar_system_id'] ?? 0));

            return (object) [
                'constellation' => $constellation->name ?? ('#'.$row['constellation_id']),
                'region' => $constellation->region ?? '?',
                'state' => (string) ($row['state'] ?? '?'),
                'faction' => $this->factionName((int) ($row['faction_id'] ?? 0)),
                'influence' => (float) ($row['influence'] ?? 0),
                'hasBoss' => (bool) ($row['has_boss'] ?? false),
                'systemCount' => count($row['infested_solar_systems'] ?? []),
                'staging' => $staging->name ?? '?',
                'stagingSecurity' => $staging !== null ? round((float) $staging->security, 1) : null,
                'distance' => $staging !== null ? ($distances[(int) $staging->system_id] ?? null) : null,
            ];
        }, $rows);

        usort($incursions, fn ($a, $b) => ($a->distance ?? PHP_INT_MAX) <=> ($b->distance ?? PHP_INT_MAX));

        return $incursions;
    }

    /**
     * Faction-warfare occupancy: per-warzone summary plus contested systems,
     * closest first.
     *
     * @return object{summary: array<string, array{faction: string, systems: int,
     *   contested: int}>, contested: list<object>}
     */
    public function factionWarfare(?int $originSystemId = null): object
    {
        try {
            $rows = $this->esi->get('/fw/systems/')->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['summary' => [], 'contested' => []];
        }

        $distances = $this->distances($originSystemId);

        $systems = DB::table('solar_systems')
            ->whereIn('system_id', array_map(fn ($r) => (int) $r['solar_system_id'], $rows))
            ->get(['system_id', 'name', 'security'])
            ->keyBy('system_id');

        $summary = [];
        $contested = [];

        foreach ($rows as $row) {
            $occupierId = (int) ($row['occupier_faction_id'] ?? 0);
            $occupier = $this->factionName($occupierId);
            $isContested = ($row['contested'] ?? '') !== 'uncontested';

            $summary[$occupierId] ??= ['faction' => $occupier, 'systems' => 0, 'contested' => 0];
            $summary[$occupierId]['systems']++;
            if ($isContested) {
                $summary[$occupierId]['contested']++;
            }

            if (! $isContested) {
                continue;
            }

            $id = (int) $row['solar_system_id'];
            $system = $systems->get($id);
            $threshold = (int) ($row['victory_points_threshold'] ?? 0);

            $contested[] = (object) [
                'systemId' => $id,
                'name' => $system->name ?? "#{$id}",
                'security' => $system !== null ? round((float) $system->security, 1) : null,
                'owner' => $this->factionName((int) ($row['owner_faction_id'] ?? 0)),
                'occupier' => $occupier,
                'state' => (string) $row['contested'],
                'contestedPct' => $threshold > 0
                    ? round(100 * (int) ($row['victory_points'] ?? 0) / $threshold, 1)
                    : 0.0,
                'distance' => $distances[$id] ?? null,
            ];
        }

        usort($contested, fn ($a, $b) => ($a->distance ?? PHP_INT_MAX) <=> ($b->distance ?? PHP_INT_MAX));

        return (object) ['summary' => $summary, 'contested' => $contested];
    }

    /** @return array<int, int> */
    private function distances(?int $originSystemId): array
    {
        return $originSystemId !== null
            ? $this->routes->distancesFrom($originSystemId, 100)
            : [];
    }
}
