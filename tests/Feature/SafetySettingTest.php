<?php

namespace Tests\Feature;

use App\Livewire\SafetySetting;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SafetySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_flips_the_setting_and_notifies_the_page(): void
    {
        $character = Character::factory()->create(); // defaults to high-sec only

        $this->assertTrue($character->avoidsLowsec());

        Livewire::test(SafetySetting::class, ['character' => $character])
            ->assertSee('HIGH-SEC ONLY')
            ->call('toggle')
            ->assertDispatched('safety-changed')
            ->assertSee('LOW-SEC OK');

        $this->assertFalse($character->refresh()->avoidsLowsec());

        Livewire::test(SafetySetting::class, ['character' => $character->refresh()])
            ->call('toggle')
            ->assertDispatched('safety-changed');

        $this->assertTrue($character->refresh()->avoidsLowsec());
    }
}
