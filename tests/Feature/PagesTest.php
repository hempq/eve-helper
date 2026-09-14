<?php

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create([
            'name' => 'Dedrok Hertrox',
            'total_sp' => 3_520_751,
            'charisma' => 19, 'intelligence' => 20, 'memory' => 20,
            'perception' => 25, 'willpower' => 20,
            'bonus_remaps' => 2,
            'last_synced_at' => now(),
        ]);
    }

    public function test_skills_page_groups_skills_with_totals(): void
    {
        $character = $this->character();

        DB::table('item_groups')->insert([
            ['group_id' => 255, 'category_id' => 16, 'name' => 'Gunnery'],
        ]);
        DB::table('item_types')->insert([
            ['type_id' => 3300, 'group_id' => 255, 'name' => 'Gunnery', 'published' => true],
            ['type_id' => 3301, 'group_id' => 255, 'name' => 'Small Hybrid Turret', 'published' => true],
        ]);
        DB::table('skill_types')->insert([
            ['type_id' => 3300, 'rank' => 1, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
            ['type_id' => 3301, 'rank' => 1, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
        ]);
        DB::table('character_skills')->insert([
            ['character_id' => $character->character_id, 'skill_id' => 3300, 'trained_level' => 5, 'active_level' => 5, 'skillpoints' => 256000],
            ['character_id' => $character->character_id, 'skill_id' => 3301, 'trained_level' => 3, 'active_level' => 2, 'skillpoints' => 8000],
        ]);

        $this->withSession(['character_id' => $character->character_id])
            ->get('/skills')
            ->assertOk()
            ->assertSee('Gunnery')
            ->assertSee('Small Hybrid Turret')
            ->assertSee('264,000') // group SP total
            ->assertSee('α 2');    // alpha-limited badge
    }

    public function test_remap_page_shows_report(): void
    {
        $character = $this->character();

        DB::table('skill_types')->insert([
            ['type_id' => 3332, 'rank' => 2, 'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower'],
        ]);
        DB::table('character_skill_queue')->insert([
            [
                'character_id' => $character->character_id, 'position' => 0, 'skill_id' => 3332,
                'finished_level' => 4, 'level_start_sp' => 40000, 'level_end_sp' => 226275,
            ],
        ]);

        $this->withSession(['character_id' => $character->character_id])
            ->get('/remap')
            ->assertOk()
            ->assertSee('Neural remap optimizer')
            ->assertSee('Time saved')
            ->assertSee('Recommended attribute map')
            ->assertSee('Queue composition');
    }

    public function test_remap_page_with_empty_queue_explains_itself(): void
    {
        $this->withSession(['character_id' => $this->character()->character_id])
            ->get('/remap')
            ->assertOk()
            ->assertSee('queue is empty');
    }

    public function test_guests_are_redirected_from_inner_pages(): void
    {
        $this->get('/skills')->assertRedirect(route('home'));
        $this->get('/remap')->assertRedirect(route('home'));
    }
}
