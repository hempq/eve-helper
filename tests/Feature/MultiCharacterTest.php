<?php

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_active_character(): void
    {
        $a = Character::factory()->create(['name' => 'Main']);
        $b = Character::factory()->create(['name' => 'Alt']);

        $this->withSession(['character_id' => $a->character_id])
            ->post(route('character.activate', $b->character_id))
            ->assertRedirect();

        $this->assertSame($b->character_id, session('character_id'));
    }

    public function test_cannot_switch_to_unknown_character(): void
    {
        $a = Character::factory()->create();

        $this->withSession(['character_id' => $a->character_id])
            ->post(route('character.activate', 999999))
            ->assertNotFound();
    }

    public function test_settings_page_updates_route_security(): void
    {
        $character = Character::factory()->create();
        $this->assertSame('highsec', $character->refresh()->route_security); // DB default

        $this->withSession(['character_id' => $character->character_id])
            ->post(route('settings.update'), ['route_security' => 'highlow'])
            ->assertRedirect(route('settings'));

        $this->assertSame('highlow', $character->refresh()->route_security);
    }

    public function test_settings_rejects_invalid_security(): void
    {
        $character = Character::factory()->create();

        $this->withSession(['character_id' => $character->character_id])
            ->post(route('settings.update'), ['route_security' => 'wormhole'])
            ->assertSessionHasErrors('route_security');
    }

    public function test_settings_requires_login(): void
    {
        $this->get(route('settings'))->assertRedirect(route('home'));
    }
}
