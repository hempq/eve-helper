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

    public function test_cycles_through_three_modes_and_notifies_the_page(): void
    {
        $character = Character::factory()->create(); // defaults to high-sec only

        $this->assertSame(0.45, $character->minRouteSecurity());

        Livewire::test(SafetySetting::class, ['character' => $character])
            ->assertSee('HIGH-SEC ONLY')
            ->call('cycle')
            ->assertDispatched('safety-changed')
            ->assertSee('HIGH + LOW');
        $this->assertSame('highlow', $character->refresh()->route_security);
        $this->assertSame(0.05, $character->minRouteSecurity());

        Livewire::test(SafetySetting::class, ['character' => $character->refresh()])
            ->call('cycle')
            ->assertSee('ANYWHERE');
        $this->assertSame('all', $character->refresh()->route_security);
        $this->assertNull($character->minRouteSecurity());

        Livewire::test(SafetySetting::class, ['character' => $character->refresh()])
            ->call('cycle')
            ->assertSee('HIGH-SEC ONLY');
        $this->assertSame('highsec', $character->refresh()->route_security);
    }
}
