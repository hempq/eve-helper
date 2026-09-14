<?php

namespace Tests\Feature\Skills;

use App\Models\Character;
use App\Services\Skills\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanBuilderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SDE fixture: C requires B2, B requires A3. All rank 1.
     */
    private function seedChain(): void
    {
        DB::table('item_types')->insert([
            ['type_id' => 100, 'group_id' => 1, 'name' => 'Skill A', 'published' => true],
            ['type_id' => 200, 'group_id' => 1, 'name' => 'Skill B', 'published' => true],
            ['type_id' => 300, 'group_id' => 1, 'name' => 'Skill C', 'published' => true],
        ]);
        DB::table('skill_types')->insert([
            ['type_id' => 100, 'rank' => 1, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
            ['type_id' => 200, 'rank' => 1, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
            ['type_id' => 300, 'rank' => 8, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
        ]);
        DB::table('skill_prerequisites')->insert([
            ['skill_id' => 200, 'required_skill_id' => 100, 'required_level' => 3],
            ['skill_id' => 300, 'required_skill_id' => 200, 'required_level' => 2],
        ]);
    }

    private function targets(array $pairs): array
    {
        return array_map(fn ($p) => (object) ['skill_id' => $p[0], 'target_level' => $p[1]], $pairs);
    }

    public function test_resolves_prerequisite_chain_in_training_order(): void
    {
        $this->seedChain();
        $character = Character::factory()->create();

        $entries = $this->app->make(PlanBuilder::class)
            ->build($character, $this->targets([[300, 1]]));

        $this->assertSame(
            [['Skill A', 1], ['Skill A', 2], ['Skill A', 3], ['Skill B', 1], ['Skill B', 2], ['Skill C', 1]],
            $entries->map(fn ($e) => [$e->name, $e->level])->all(),
        );

        // Everything except the goal itself is a prerequisite.
        $this->assertSame([true, true, true, true, true, false], $entries->map(fn ($e) => $e->isPrerequisite)->all());

        // Rank-8 goal: level 1 costs 250*8 = 2000 SP.
        $this->assertSame(2000, $entries->last()->sp);
    }

    public function test_trained_levels_and_partial_sp_are_credited(): void
    {
        $this->seedChain();
        $character = Character::factory()->create();

        // A is at 2 with 3000 SP: 1414 SP (level 2 total) + 1586 into level 3.
        DB::table('character_skills')->insert([
            'character_id' => $character->character_id, 'skill_id' => 100,
            'trained_level' => 2, 'active_level' => 2, 'skillpoints' => 3000,
        ]);

        $entries = $this->app->make(PlanBuilder::class)
            ->build($character, $this->targets([[200, 1]]));

        $this->assertSame(
            [['Skill A', 3], ['Skill B', 1]],
            $entries->map(fn ($e) => [$e->name, $e->level])->all(),
        );

        // Level 3 needs 8000-1414 = 6586, minus the 1586 partial = 5000.
        $this->assertSame(5000, $entries->first()->sp);
    }

    public function test_duplicate_targets_and_satisfied_goals_collapse(): void
    {
        $this->seedChain();
        $character = Character::factory()->create();
        DB::table('character_skills')->insert([
            'character_id' => $character->character_id, 'skill_id' => 100,
            'trained_level' => 5, 'active_level' => 5, 'skillpoints' => 256000,
        ]);

        // A5 already trained; B listed twice at different levels.
        $entries = $this->app->make(PlanBuilder::class)
            ->build($character, $this->targets([[100, 3], [200, 1], [200, 2]]));

        $this->assertSame(
            [['Skill B', 1], ['Skill B', 2]],
            $entries->map(fn ($e) => [$e->name, $e->level])->all(),
        );
    }

    public function test_survives_cyclic_prerequisite_data(): void
    {
        $this->seedChain();
        // Introduce a bogus cycle: A requires C1.
        DB::table('skill_prerequisites')->insert([
            'skill_id' => 100, 'required_skill_id' => 300, 'required_level' => 1,
        ]);

        $character = Character::factory()->create();

        $entries = $this->app->make(PlanBuilder::class)
            ->build($character, $this->targets([[300, 1]]));

        // No infinite loop; C1 still trains once.
        $this->assertSame(1, $entries->where('name', 'Skill C')->count());
    }
}
