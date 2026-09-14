<?php

namespace Tests\Feature\Sde;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SdeImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): string
    {
        return base_path('tests/Fixtures/sde');
    }

    public function test_command_imports_everything_from_a_directory(): void
    {
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])
            ->assertSuccessful();

        $this->assertSame(7, DB::table('item_types')->count());
        $this->assertSame(4, DB::table('item_groups')->count());
        $this->assertSame('Gunnery', DB::table('item_groups')->where('group_id', 255)->value('name'));
        $this->assertSame(3, DB::table('skill_types')->count());
        $this->assertSame(1, DB::table('skill_prerequisites')->count());
        $this->assertSame(2, DB::table('implant_bonuses')->count());
        $this->assertSame(1, DB::table('regions')->count());
        $this->assertSame(1, DB::table('constellations')->count());
        $this->assertSame(2, DB::table('solar_systems')->count());
        $this->assertSame(2, DB::table('system_jumps')->count());
    }

    public function test_prerequisites_and_implant_bonuses_are_extracted(): void
    {
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])->assertSuccessful();

        // Spaceship Command (3327) requires Gunnery (3300) at level 2.
        $prereq = DB::table('skill_prerequisites')->where('skill_id', 3327)->first();
        $this->assertSame(3300, (int) $prereq->required_skill_id);
        $this->assertSame(2, (int) $prereq->required_level);

        // The ship's stray requiredSkill dogma row must not create a prerequisite.
        $this->assertSame(0, DB::table('skill_prerequisites')->where('skill_id', 587)->count());

        // Ocular Filter: +4 perception (valueFloat) and +4 charisma (valueInt).
        $bonuses = DB::table('implant_bonuses')->where('type_id', 10216)
            ->pluck('bonus', 'attribute')->all();
        $this->assertEquals(['perception' => 4, 'charisma' => 4], $bonuses);

        // The bogus 2021 "bonus" on the event booster must be filtered out.
        $this->assertSame(0, DB::table('implant_bonuses')->where('type_id', 57300)->count());
    }

    public function test_types_are_imported_with_nulls_normalized(): void
    {
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])->assertSuccessful();

        $gunnery = DB::table('item_types')->where('type_id', 3300)->first();
        $this->assertNotNull($gunnery);
        $this->assertSame('Gunnery', $gunnery->name);
        $this->assertTrue((bool) $gunnery->published);

        $dead = DB::table('item_types')->where('type_id', 9955)->first();
        $this->assertFalse((bool) $dead->published);
        $this->assertNull($dead->market_group_id); // "None" in the CSV
    }

    public function test_skills_get_rank_and_attributes_and_ships_are_excluded(): void
    {
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])->assertSuccessful();

        $gunnery = DB::table('skill_types')->where('type_id', 3300)->first();
        $this->assertSame(1, (int) $gunnery->rank);
        $this->assertSame('perception', $gunnery->primary_attribute);
        $this->assertSame('willpower', $gunnery->secondary_attribute);

        // valueInt variant (Science rank stored as int column)
        $science = DB::table('skill_types')->where('type_id', 3402)->first();
        $this->assertSame(1, (int) $science->rank);
        $this->assertSame('intelligence', $science->primary_attribute);
        $this->assertSame('memory', $science->secondary_attribute);

        // The Rifter (a ship) must not appear even though it has dogma rows.
        $this->assertNull(DB::table('skill_types')->where('type_id', 587)->first());
    }

    public function test_universe_import_builds_map_tables(): void
    {
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])->assertSuccessful();

        $jita = DB::table('solar_systems')->where('system_id', 30000142)->first();
        $this->assertSame('Jita', $jita->name);
        $this->assertSame(10000002, (int) $jita->region_id);
        $this->assertSame(20000020, (int) $jita->constellation_id);
        $this->assertEqualsWithDelta(0.9459, (float) $jita->security, 0.001);

        $this->assertSame('The Forge', DB::table('regions')->where('region_id', 10000002)->value('name'));
        $this->assertSame('Kimotoro', DB::table('constellations')->where('constellation_id', 20000020)->value('name'));

        $jumps = DB::table('system_jumps')->orderBy('from_system_id')->get();
        $this->assertSame([[30000142, 30000144], [30000144, 30000142]], $jumps->map(
            fn ($j) => [(int) $j->from_system_id, (int) $j->to_system_id],
        )->all());
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])->assertSuccessful();
        $this->artisan('eve:sde-import', ['--dir' => $this->fixtures()])->assertSuccessful();

        $this->assertSame(7, DB::table('item_types')->count());
        $this->assertSame(3, DB::table('skill_types')->count());
        $this->assertSame(1, DB::table('skill_prerequisites')->count());
        $this->assertSame(2, DB::table('implant_bonuses')->count());
        $this->assertSame(2, DB::table('system_jumps')->count());
    }
}
