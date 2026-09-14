<?php

namespace App\Services\Sde;

use Illuminate\Support\Facades\DB;

/**
 * Imports the subset of the EVE Static Data Export the app needs, from
 * Fuzzwork's CSV conversions (https://www.fuzzwork.co.uk/dump/latest/).
 */
class SdeImporter
{
    private const CHUNK = 1000;

    /** dogma attribute ids on skill types */
    private const ATTR_PRIMARY = 180;

    private const ATTR_SECONDARY = 181;

    private const ATTR_RANK = 275;

    /** dogma attribute id => character attribute name */
    private const CHARACTER_ATTRIBUTES = [
        164 => 'charisma',
        165 => 'intelligence',
        166 => 'memory',
        167 => 'perception',
        168 => 'willpower',
    ];

    /** requiredSkillN dogma attribute id => matching requiredSkillNLevel id */
    private const PREREQUISITE_PAIRS = [
        182 => 277,
        183 => 278,
        184 => 279,
        1285 => 1286,
        1289 => 1287,
        1290 => 1288,
    ];

    /** attribute-bonus dogma id (implants) => character attribute name */
    private const IMPLANT_BONUS_ATTRIBUTES = [
        175 => 'charisma',
        176 => 'intelligence',
        177 => 'memory',
        178 => 'perception',
        179 => 'willpower',
    ];

    private const SKILL_CATEGORY_ID = 16;

    public function __construct(private readonly CsvReader $csv) {}

    public function importTypes(string $invTypesPath): int
    {
        return $this->upsertChunked('item_types', 'type_id', (function () use ($invTypesPath) {
            foreach ($this->csv->rows($invTypesPath) as $row) {
                yield [
                    'type_id' => (int) $row['typeID'],
                    'group_id' => (int) $row['groupID'],
                    'name' => (string) $row['typeName'],
                    'volume' => $row['volume'] !== null ? (float) $row['volume'] : null,
                    'market_group_id' => $row['marketGroupID'] !== null ? (int) $row['marketGroupID'] : null,
                    'published' => (bool) (int) ($row['published'] ?? 0),
                ];
            }
        })());
    }

    public function importGroups(string $invGroupsPath): int
    {
        return $this->upsertChunked('item_groups', 'group_id', (function () use ($invGroupsPath) {
            foreach ($this->csv->rows($invGroupsPath) as $row) {
                yield [
                    'group_id' => (int) $row['groupID'],
                    'category_id' => (int) $row['categoryID'],
                    'name' => (string) $row['groupName'],
                ];
            }
        })());
    }

    /**
     * Single pass over the (large) dogma attribute dump filling three tables:
     * skill_types, skill_prerequisites and implant_bonuses. Requires
     * importTypes() to have run: skill type ids are looked up from item_types
     * via their groups' category.
     *
     * @return array{skills: int, prerequisites: int, implant_bonuses: int}
     */
    public function importDogma(string $invGroupsPath, string $dgmTypeAttributesPath): array
    {
        $skillGroupIds = [];
        foreach ($this->csv->rows($invGroupsPath) as $row) {
            if ((int) $row['categoryID'] === self::SKILL_CATEGORY_ID) {
                $skillGroupIds[] = (int) $row['groupID'];
            }
        }

        $skillTypeIds = array_flip(
            DB::table('item_types')->whereIn('group_id', $skillGroupIds)->pluck('type_id')->all(),
        );

        $skills = [];
        $rawPrereqs = [];
        $implantBonuses = [];

        foreach ($this->csv->rows($dgmTypeAttributesPath) as $row) {
            $typeId = (int) $row['typeID'];
            $attributeId = (int) $row['attributeID'];
            $value = (int) (float) ($row['valueFloat'] ?? $row['valueInt'] ?? 0);

            // Bonus above 50 is bogus data (e.g. a year stored on some
            // event boosters), not a real attribute bonus.
            if (isset(self::IMPLANT_BONUS_ATTRIBUTES[$attributeId]) && $value > 0 && $value <= 50 && ! isset($skillTypeIds[$typeId])) {
                $implantBonuses[] = [
                    'type_id' => $typeId,
                    'attribute' => self::IMPLANT_BONUS_ATTRIBUTES[$attributeId],
                    'bonus' => $value,
                ];

                continue;
            }

            if (! isset($skillTypeIds[$typeId])) {
                continue;
            }

            match (true) {
                $attributeId === self::ATTR_RANK => $skills[$typeId]['rank'] = $value,
                $attributeId === self::ATTR_PRIMARY => $skills[$typeId]['primary_attribute'] = self::CHARACTER_ATTRIBUTES[$value] ?? null,
                $attributeId === self::ATTR_SECONDARY => $skills[$typeId]['secondary_attribute'] = self::CHARACTER_ATTRIBUTES[$value] ?? null,
                isset(self::PREREQUISITE_PAIRS[$attributeId]) => $rawPrereqs[$typeId]['skills'][$attributeId] = $value,
                in_array($attributeId, self::PREREQUISITE_PAIRS, true) => $rawPrereqs[$typeId]['levels'][$attributeId] = $value,
                default => null,
            };
        }

        $skillCount = $this->upsertChunked('skill_types', 'type_id', (function () use ($skills) {
            foreach ($skills as $typeId => $attributes) {
                // Skip incomplete rows (a handful of unpublished/broken skills).
                if (! isset($attributes['rank'], $attributes['primary_attribute'], $attributes['secondary_attribute'])) {
                    continue;
                }

                yield ['type_id' => $typeId, ...$attributes];
            }
        })());

        $prereqCount = $this->upsertChunked('skill_prerequisites', ['skill_id', 'required_skill_id'], (function () use ($rawPrereqs) {
            foreach ($rawPrereqs as $typeId => $raw) {
                foreach ($raw['skills'] ?? [] as $skillAttrId => $requiredSkillId) {
                    $level = $raw['levels'][self::PREREQUISITE_PAIRS[$skillAttrId]] ?? null;

                    if ($requiredSkillId > 0 && $level !== null && $level > 0) {
                        yield [
                            'skill_id' => $typeId,
                            'required_skill_id' => $requiredSkillId,
                            'required_level' => $level,
                        ];
                    }
                }
            }
        })());

        $bonusCount = $this->upsertChunked('implant_bonuses', ['type_id', 'attribute'], (function () use ($implantBonuses) {
            yield from $implantBonuses;
        })());

        return [
            'skills' => $skillCount,
            'prerequisites' => $prereqCount,
            'implant_bonuses' => $bonusCount,
        ];
    }

    public function importStations(string $staStationsPath): int
    {
        return $this->upsertChunked('stations', 'station_id', (function () use ($staStationsPath) {
            foreach ($this->csv->rows($staStationsPath) as $row) {
                yield [
                    'station_id' => (int) $row['stationID'],
                    'system_id' => (int) $row['solarSystemID'],
                    'name' => (string) $row['stationName'],
                    'corporation_id' => (int) $row['corporationID'] ?: null,
                ];
            }
        })());
    }

    public function importAgents(string $agtAgentsPath): int
    {
        return $this->upsertChunked('agents', 'agent_id', (function () use ($agtAgentsPath) {
            foreach ($this->csv->rows($agtAgentsPath) as $row) {
                yield [
                    'agent_id' => (int) $row['agentID'],
                    'division_id' => (int) $row['divisionID'],
                    'corporation_id' => (int) $row['corporationID'],
                    'location_id' => (int) $row['locationID'],
                    'level' => (int) $row['level'],
                    'agent_type_id' => (int) $row['agentTypeID'],
                    'is_locator' => (bool) $row['isLocator'],
                ];
            }
        })());
    }

    public function importNpcCorporations(string $crpNPCCorporationsPath): int
    {
        return $this->upsertChunked('npc_corporations', 'corporation_id', (function () use ($crpNPCCorporationsPath) {
            foreach ($this->csv->rows($crpNPCCorporationsPath) as $row) {
                yield [
                    'corporation_id' => (int) $row['corporationID'],
                    'name' => (string) $row['corporationName'],
                    'faction_id' => (int) $row['factionID'] ?: null,
                ];
            }
        })());
    }

    public function importFactions(string $chrFactionsPath): int
    {
        return $this->upsertChunked('factions', 'faction_id', (function () use ($chrFactionsPath) {
            foreach ($this->csv->rows($chrFactionsPath) as $row) {
                yield [
                    'faction_id' => (int) $row['factionID'],
                    'name' => (string) $row['factionName'],
                ];
            }
        })());
    }

    public function importDivisions(string $crpNPCDivisionsPath): int
    {
        return $this->upsertChunked('npc_divisions', 'division_id', (function () use ($crpNPCDivisionsPath) {
            foreach ($this->csv->rows($crpNPCDivisionsPath) as $row) {
                yield [
                    'division_id' => (int) $row['divisionID'],
                    'name' => (string) $row['divisionName'],
                ];
            }
        })());
    }

    public function importRegions(string $mapRegionsPath): int
    {
        return $this->upsertChunked('regions', 'region_id', (function () use ($mapRegionsPath) {
            foreach ($this->csv->rows($mapRegionsPath) as $row) {
                yield [
                    'region_id' => (int) $row['regionID'],
                    'name' => (string) $row['regionName'],
                ];
            }
        })());
    }

    public function importConstellations(string $mapConstellationsPath): int
    {
        return $this->upsertChunked('constellations', 'constellation_id', (function () use ($mapConstellationsPath) {
            foreach ($this->csv->rows($mapConstellationsPath) as $row) {
                yield [
                    'constellation_id' => (int) $row['constellationID'],
                    'region_id' => (int) $row['regionID'],
                    'name' => (string) $row['constellationName'],
                ];
            }
        })());
    }

    public function importSolarSystems(string $mapSolarSystemsPath): int
    {
        return $this->upsertChunked('solar_systems', 'system_id', (function () use ($mapSolarSystemsPath) {
            foreach ($this->csv->rows($mapSolarSystemsPath) as $row) {
                yield [
                    'system_id' => (int) $row['solarSystemID'],
                    'constellation_id' => (int) $row['constellationID'],
                    'region_id' => (int) $row['regionID'],
                    'name' => (string) $row['solarSystemName'],
                    'security' => (float) $row['security'],
                ];
            }
        })());
    }

    public function importSystemJumps(string $mapSolarSystemJumpsPath): int
    {
        return $this->upsertChunked('system_jumps', ['from_system_id', 'to_system_id'], (function () use ($mapSolarSystemJumpsPath) {
            foreach ($this->csv->rows($mapSolarSystemJumpsPath) as $row) {
                yield [
                    'from_system_id' => (int) $row['fromSolarSystemID'],
                    'to_system_id' => (int) $row['toSolarSystemID'],
                ];
            }
        })());
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function upsertChunked(string $table, string|array $uniqueBy, iterable $rows): int
    {
        $count = 0;
        $chunk = [];

        foreach ($rows as $row) {
            $chunk[] = $row;

            if (count($chunk) >= self::CHUNK) {
                DB::table($table)->upsert($chunk, $uniqueBy);
                $count += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table($table)->upsert($chunk, $uniqueBy);
            $count += count($chunk);
        }

        return $count;
    }
}
