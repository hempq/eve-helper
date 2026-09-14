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
 * Planetary-industry colonies with their extractor expiry — the point of PI
 * alerting is never letting an extraction program idle unnoticed.
 * Needs esi-planets.manage_planets.v1 (re-login to grant).
 */
class PlanetaryService
{
    public function __construct(private readonly EsiClientInterface $esi) {}

    /**
     * @return object{needsScope: bool, colonies: Collection<int, object>}
     */
    public function colonies(Character $character): object
    {
        try {
            $planets = $this->esi->get("/characters/{$character->character_id}/planets", [], $character)->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['needsScope' => true, 'colonies' => collect()];
        }

        $systemNames = DB::table('solar_systems')
            ->whereIn('system_id', array_map(fn ($p) => (int) $p['solar_system_id'], $planets))
            ->pluck('name', 'system_id');

        $colonies = collect($planets)->map(function (array $planet) use ($character, $systemNames) {
            $expiry = $this->earliestExtractorExpiry($character, (int) $planet['planet_id']);

            return (object) [
                'planetId' => (int) $planet['planet_id'],
                'system' => $systemNames[(int) $planet['solar_system_id']] ?? ('#'.$planet['solar_system_id']),
                'planetType' => ucfirst((string) ($planet['planet_type'] ?? '?')),
                'upgradeLevel' => (int) ($planet['upgrade_level'] ?? 0),
                'pins' => (int) ($planet['num_pins'] ?? 0),
                'extractorExpiry' => $expiry,
                'expired' => $expiry !== null && $expiry->isPast(),
            ];
        })->sortBy(fn ($c) => $c->extractorExpiry?->timestamp ?? PHP_INT_MAX)->values();

        return (object) ['needsScope' => false, 'colonies' => $colonies];
    }

    private function earliestExtractorExpiry(Character $character, int $planetId): ?CarbonImmutable
    {
        try {
            $detail = $this->esi->get("/characters/{$character->character_id}/planets/{$planetId}", [], $character)->data;
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return null;
        }

        $expiries = [];
        foreach ($detail['pins'] ?? [] as $pin) {
            if (isset($pin['expiry_time'], $pin['extractor_details'])) {
                $expiries[] = CarbonImmutable::parse($pin['expiry_time']);
            }
        }

        return $expiries === [] ? null : min($expiries);
    }
}
