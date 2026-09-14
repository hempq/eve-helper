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

    /**
     * Requires importTypes() to have run: skill type ids are looked up from
     * item_types via their groups' category.
     */
    public function importSkills(string $invGroupsPath, string $dgmTypeAttributesPath): int
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
        foreach ($this->csv->rows($dgmTypeAttributesPath) as $row) {
            $typeId = (int) $row['typeID'];
            if (! isset($skillTypeIds[$typeId])) {
                continue;
            }

            $attributeId = (int) $row['attributeID'];
            $value = (int) (float) ($row['valueFloat'] ?? $row['valueInt'] ?? 0);

            match ($attributeId) {
                self::ATTR_RANK => $skills[$typeId]['rank'] = $value,
                self::ATTR_PRIMARY => $skills[$typeId]['primary_attribute'] = self::CHARACTER_ATTRIBUTES[$value] ?? null,
                self::ATTR_SECONDARY => $skills[$typeId]['secondary_attribute'] = self::CHARACTER_ATTRIBUTES[$value] ?? null,
                default => null,
            };
        }

        return $this->upsertChunked('skill_types', 'type_id', (function () use ($skills) {
            foreach ($skills as $typeId => $attributes) {
                // Skip incomplete rows (a handful of unpublished/broken skills).
                if (! isset($attributes['rank'], $attributes['primary_attribute'], $attributes['secondary_attribute'])) {
                    continue;
                }

                yield ['type_id' => $typeId, ...$attributes];
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
