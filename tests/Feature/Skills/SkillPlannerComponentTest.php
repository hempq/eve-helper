<?php

namespace Tests\Feature\Skills;

use App\Livewire\SkillPlanner;
use App\Models\Character;
use App\Models\SkillPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SkillPlannerComponentTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create([
            'charisma' => 19, 'intelligence' => 20, 'memory' => 20,
            'perception' => 25, 'willpower' => 20,
        ]);

        DB::table('item_types')->insert([
            ['type_id' => 100, 'group_id' => 1, 'name' => 'Skill Alpha', 'published' => true],
            ['type_id' => 200, 'group_id' => 1, 'name' => 'Skill Beta', 'published' => true],
        ]);
        DB::table('skill_types')->insert([
            ['type_id' => 100, 'rank' => 1, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
            ['type_id' => 200, 'rank' => 2, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
        ]);
        DB::table('skill_prerequisites')->insert([
            ['skill_id' => 200, 'required_skill_id' => 100, 'required_level' => 2],
        ]);
    }

    public function test_search_finds_skills(): void
    {
        Livewire::test(SkillPlanner::class, ['character' => $this->character])
            ->set('search', 'Beta')
            ->assertSee('Skill Beta')
            ->assertDontSee('Skill Alpha');
    }

    public function test_adding_a_goal_builds_plan_with_prerequisites(): void
    {
        Livewire::test(SkillPlanner::class, ['character' => $this->character])
            ->set('level', 1)
            ->call('addSkill', 200)
            ->assertSee('Skill Alpha')   // prereq appears in the plan
            ->assertSee('prereq')
            ->assertSee('Training order');

        $plan = SkillPlan::defaultFor($this->character);
        $this->assertSame(1, $plan->targets()->count());
        $this->assertSame(200, (int) $plan->targets()->first()->skill_id);
    }

    public function test_adding_same_skill_keeps_highest_level(): void
    {
        Livewire::test(SkillPlanner::class, ['character' => $this->character])
            ->set('level', 4)
            ->call('addSkill', 100)
            ->set('level', 2)
            ->call('addSkill', 100);

        $target = SkillPlan::defaultFor($this->character)->targets()->first();
        $this->assertSame(4, (int) $target->target_level);
    }

    public function test_remove_and_clear(): void
    {
        $component = Livewire::test(SkillPlanner::class, ['character' => $this->character])
            ->set('level', 3)
            ->call('addSkill', 100);

        $targetId = SkillPlan::defaultFor($this->character)->targets()->first()->id;

        $component->call('removeTarget', $targetId)
            ->assertSee('The plan is empty');

        $this->assertSame(0, SkillPlan::defaultFor($this->character)->targets()->count());
    }
}
