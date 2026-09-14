<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Services\Characters\CharacterSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_login_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Log in with EVE Online');
    }

    public function test_logged_in_character_sees_dashboard_with_queue(): void
    {
        $character = Character::factory()->create([
            'name' => 'Dedrok Hertrox',
            'total_sp' => 3_520_751,
            'unallocated_sp' => 1_500,
            'charisma' => 23, 'intelligence' => 24, 'memory' => 24,
            'perception' => 24, 'willpower' => 24,
            'bonus_remaps' => 2,
            'last_synced_at' => now(),
        ]);

        DB::table('item_types')->insert([
            'type_id' => 3332, 'group_id' => 255, 'name' => 'Targeting', 'published' => true,
        ]);
        DB::table('skill_types')->insert([
            'type_id' => 3332, 'rank' => 1,
            'primary_attribute' => 'perception', 'secondary_attribute' => 'willpower',
        ]);
        DB::table('character_skill_queue')->insert([
            'character_id' => $character->character_id,
            'position' => 0,
            'skill_id' => 3332,
            'finished_level' => 4,
            'start_date' => now()->subDay(),
            'finish_date' => now()->addDay(),
            'level_start_sp' => 40000,
            'level_end_sp' => 226275,
            'training_start_sp' => 110816,
        ]);

        // Sync runs in the controller and in the TrainingStatus Livewire
        // component; both go through the staleness guard.
        $this->mock(CharacterSyncService::class)
            ->shouldReceive('sync')->twice();

        $this->withSession(['character_id' => $character->character_id])
            ->get('/')
            ->assertOk()
            ->assertSee('Dedrok Hertrox')
            ->assertSee('3,520,751')
            ->assertSee('Targeting')
            ->assertSee('50%') // halfway between start and finish
            ->assertSee('2,160 SP/h'); // (24 + 24/2) * 60
    }

    public function test_paused_queue_is_labelled(): void
    {
        $character = Character::factory()->create([
            'charisma' => 20, 'intelligence' => 20, 'memory' => 20,
            'perception' => 20, 'willpower' => 20,
            'last_synced_at' => now(),
        ]);

        DB::table('character_skill_queue')->insert([
            'character_id' => $character->character_id,
            'position' => 0,
            'skill_id' => 3327,
            'finished_level' => 5,
        ]);

        $this->mock(CharacterSyncService::class)->shouldReceive('sync')->twice();

        $this->withSession(['character_id' => $character->character_id])
            ->get('/')
            ->assertOk()
            ->assertSee('queue paused');
    }

    public function test_stale_session_for_deleted_character_falls_back_to_login(): void
    {
        $this->withSession(['character_id' => 424242])
            ->get('/')
            ->assertOk()
            ->assertSee('Log in with EVE Online');
    }
}
