<?php

namespace App\Services\Industry;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Industry jobs (with ready-to-deliver detection) and the character's
 * blueprint library. Needs esi-industry.read_character_jobs.v1 and
 * esi-characters.read_blueprints.v1 (re-login to grant).
 */
class IndustryService
{
    private const ACTIVITIES = [
        1 => 'Manufacturing', 3 => 'TE research', 4 => 'ME research',
        5 => 'Copying', 8 => 'Invention', 9 => 'Reactions', 11 => 'Reactions',
    ];

    public function __construct(private readonly EsiClientInterface $esi) {}

    /**
     * @return object{needsScope: bool, jobs: Collection<int, object>, readyCount: int}
     */
    public function jobs(Character $character): object
    {
        try {
            $rows = $this->esi->get("/characters/{$character->character_id}/industry/jobs", [], $character)->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['needsScope' => true, 'jobs' => collect(), 'readyCount' => 0];
        }

        $names = DB::table('item_types')
            ->whereIn('type_id', array_filter(array_map(fn ($r) => (int) ($r['product_type_id'] ?? $r['blueprint_type_id'] ?? 0), $rows)))
            ->pluck('name', 'type_id');

        $jobs = collect($rows)
            ->filter(fn (array $r) => ($r['status'] ?? '') === 'active')
            ->map(function (array $row) use ($names) {
                $end = CarbonImmutable::parse($row['end_date']);
                $productId = (int) ($row['product_type_id'] ?? $row['blueprint_type_id'] ?? 0);

                return (object) [
                    'activity' => self::ACTIVITIES[(int) ($row['activity_id'] ?? 0)] ?? 'Industry',
                    'product' => $names[$productId] ?? ('Type #'.$productId),
                    'runs' => (int) ($row['runs'] ?? 1),
                    'endsAt' => $end,
                    'ready' => $end->isPast(),
                ];
            })
            ->sortBy(fn ($j) => $j->endsAt->timestamp)
            ->values();

        return (object) [
            'needsScope' => false,
            'jobs' => $jobs,
            'readyCount' => $jobs->where('ready', true)->count(),
        ];
    }

    /**
     * @return object{needsScope: bool, blueprints: Collection<int, object>, originals: int, copies: int}
     */
    public function blueprints(Character $character): object
    {
        try {
            $rows = $this->esi->getAllPages("/characters/{$character->character_id}/blueprints", [], $character);
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['needsScope' => true, 'blueprints' => collect(), 'originals' => 0, 'copies' => 0];
        }

        $names = DB::table('item_types')
            ->whereIn('type_id', array_unique(array_map(fn ($r) => (int) $r['type_id'], $rows)))
            ->pluck('name', 'type_id');

        // Aggregate identical blueprints (same type/ME/TE/kind).
        $grouped = [];
        foreach ($rows as $row) {
            $isCopy = (int) ($row['quantity'] ?? -1) === -2 || (int) ($row['runs'] ?? -1) > 0;
            $key = $row['type_id'].'|'.$row['material_efficiency'].'|'.$row['time_efficiency'].'|'.($isCopy ? 'c' : 'o');

            $entry = $grouped[$key] ??= (object) [
                'typeId' => (int) $row['type_id'],
                'name' => $names[(int) $row['type_id']] ?? ('Type #'.$row['type_id']),
                'isCopy' => $isCopy,
                'me' => (int) ($row['material_efficiency'] ?? 0),
                'te' => (int) ($row['time_efficiency'] ?? 0),
                'count' => 0,
                'runs' => 0,
            ];
            $entry->count += max(1, (int) ($row['quantity'] ?? 1) > 0 ? (int) $row['quantity'] : 1);
            $entry->runs += max(0, (int) ($row['runs'] ?? 0));
        }

        $blueprints = collect(array_values($grouped))
            ->sortBy([['isCopy', 'asc'], ['name', 'asc']])
            ->values();

        return (object) [
            'needsScope' => false,
            'blueprints' => $blueprints,
            'originals' => $blueprints->where('isCopy', false)->sum('count'),
            'copies' => $blueprints->where('isCopy', true)->sum('count'),
        ];
    }
}
