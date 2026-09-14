<?php

namespace App\Services\Killmails;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Recent ship losses and ISK lost, from the public zKillboard API (no ESI
 * scope needed). zKill only lists killmail id + hash + value; ship, system
 * and time come from the public ESI killmail endpoint. Killmails are
 * immutable, so those details are cached forever.
 */
class LossHistoryService
{
    private const ZKILL_CACHE_SECONDS = 900;

    public function __construct(
        private readonly Cache $cache,
        private readonly EsiClientInterface $esi,
        private readonly string $userAgent,
    ) {}

    /**
     * @return object{available: bool, losses: Collection<int, object>,
     *   totalValue: float, count30d: int, value30d: float, truncated: bool}
     */
    public function summary(Character $character, int $detailLimit = 25): object
    {
        $rows = $this->zkillLosses($character->character_id);

        if ($rows === null) {
            return $this->empty(available: false);
        }

        $losses = collect($rows)
            ->filter(fn ($row) => isset($row['killmail_id'], $row['zkb']['hash']))
            ->take($detailLimit)
            ->map(function (array $row) {
                $detail = $this->killmailDetail((int) $row['killmail_id'], (string) $row['zkb']['hash']);

                return (object) [
                    'killmailId' => (int) $row['killmail_id'],
                    'value' => (float) ($row['zkb']['totalValue'] ?? 0),
                    'droppedValue' => (float) ($row['zkb']['droppedValue'] ?? 0),
                    'npc' => (bool) ($row['zkb']['npc'] ?? false),
                    'solo' => (bool) ($row['zkb']['solo'] ?? false),
                    'time' => $detail?->time,
                    'shipTypeId' => $detail?->shipTypeId,
                    'systemId' => $detail?->systemId,
                ];
            })
            ->values();

        $this->resolveNames($losses);

        $cutoff = CarbonImmutable::now()->subDays(30);
        $recent30 = $losses->filter(fn ($l) => $l->time !== null && $l->time->isAfter($cutoff));

        return (object) [
            'available' => true,
            'losses' => $losses,
            // Page 1 covers the character's 200 newest losses — for a normal
            // player that is effectively "all of them".
            'totalValue' => (float) collect($rows)->sum(fn ($r) => (float) ($r['zkb']['totalValue'] ?? 0)),
            'count30d' => $recent30->count(),
            'value30d' => (float) $recent30->sum('value'),
            // True when losses beyond the detailed window may fall inside 30d.
            'truncated' => count($rows) > $detailLimit
                && ($losses->last()?->time === null || $losses->last()->time->isAfter($cutoff)),
        ];
    }

    /**
     * zKillboard's recent-loss listing (newest first, max 200 rows). Returns
     * null when zKill is unreachable so the card can say so.
     *
     * @return ?list<array{killmail_id: int, zkb: array}>
     */
    private function zkillLosses(int $characterId): ?array
    {
        return $this->cache->remember(
            "zkill:losses:{$characterId}",
            self::ZKILL_CACHE_SECONDS,
            function () use ($characterId): ?array {
                try {
                    $rows = Http::withHeaders(['User-Agent' => $this->userAgent])
                        ->timeout(15)
                        ->get("https://zkillboard.com/api/losses/characterID/{$characterId}/")
                        ->throw()
                        ->json();
                } catch (Throwable) {
                    return null;
                }

                return is_array($rows) ? array_values($rows) : null;
            },
        );
    }

    /**
     * @return ?object{time: CarbonImmutable, shipTypeId: int, systemId: int}
     */
    private function killmailDetail(int $killmailId, string $hash): ?object
    {
        $cached = $this->cache->rememberForever(
            "killmail:{$killmailId}",
            function () use ($killmailId, $hash): ?array {
                try {
                    $data = $this->esi->get("/killmails/{$killmailId}/{$hash}/")->data;
                } catch (EsiErrorLimited|EsiRequestFailed) {
                    return null;
                }

                return [
                    'time' => $data['killmail_time'] ?? null,
                    'ship_type_id' => (int) ($data['victim']['ship_type_id'] ?? 0),
                    'system_id' => (int) ($data['solar_system_id'] ?? 0),
                ];
            },
        );

        // A stored null reads back as a cache miss, so failed fetches are
        // retried on the next load rather than cached forever.
        if ($cached === null) {
            return null;
        }

        return (object) [
            'time' => $cached['time'] !== null ? CarbonImmutable::parse($cached['time']) : null,
            'shipTypeId' => $cached['ship_type_id'],
            'systemId' => $cached['system_id'],
        ];
    }

    /** Adds shipName / systemName / security to each loss row in place. */
    private function resolveNames(Collection $losses): void
    {
        $shipNames = DB::table('item_types')
            ->whereIn('type_id', $losses->pluck('shipTypeId')->filter()->unique())
            ->pluck('name', 'type_id');
        $systems = DB::table('solar_systems')
            ->whereIn('system_id', $losses->pluck('systemId')->filter()->unique())
            ->get(['system_id', 'name', 'security'])
            ->keyBy('system_id');

        foreach ($losses as $loss) {
            $loss->shipName = $loss->shipTypeId !== null
                ? ($shipNames[$loss->shipTypeId] ?? 'Ship #'.$loss->shipTypeId)
                : null;
            $system = $loss->systemId !== null ? $systems->get($loss->systemId) : null;
            $loss->systemName = $system?->name;
            $loss->security = $system !== null ? (float) $system->security : null;
        }
    }

    private function empty(bool $available): object
    {
        return (object) [
            'available' => $available,
            'losses' => collect(),
            'totalValue' => 0.0,
            'count30d' => 0,
            'value30d' => 0.0,
            'truncated' => false,
        ];
    }
}
