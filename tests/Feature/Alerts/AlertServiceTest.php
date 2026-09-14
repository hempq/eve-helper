<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Character;
use App\Services\Alerts\AlertService;
use App\Services\Market\UndercutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No undercut orders by default.
        $this->mock(UndercutService::class)->shouldReceive('check')->andReturn(collect());
    }

    public function test_empty_skill_queue_raises_urgent_alert(): void
    {
        $character = Character::factory()->create();

        $this->app->make(AlertService::class)->refresh($character);

        $alert = Alert::where('character_id', $character->character_id)->first();
        $this->assertSame('skill_queue', $alert->type);
        $this->assertSame('urgent', $alert->severity);
        $this->assertStringContainsString('empty', $alert->message);
    }

    public function test_queue_ending_soon_warns_and_clears_when_extended(): void
    {
        $character = Character::factory()->create();
        DB::table('character_skill_queue')->insert([
            'character_id' => $character->character_id, 'position' => 0, 'skill_id' => 1,
            'finished_level' => 5, 'finish_date' => now()->addHours(10),
        ]);

        $svc = $this->app->make(AlertService::class);
        $svc->refresh($character);
        $this->assertSame('skill_queue_ending', Alert::first()->dedupe_key);

        // Extend the queue past 24h -> the alert clears.
        DB::table('character_skill_queue')->update(['finish_date' => now()->addDays(10)]);
        $svc->refresh($character);
        $this->assertSame(0, Alert::count());
    }

    public function test_expiring_escalation_alert(): void
    {
        $character = Character::factory()->create();
        DB::table('solar_systems')->insert(['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9]);
        DB::table('character_skill_queue')->insert([ // avoid the empty-queue alert
            'character_id' => $character->character_id, 'position' => 0, 'skill_id' => 1,
            'finished_level' => 5, 'finish_date' => now()->addMonth(),
        ]);
        DB::table('signatures')->insert([
            'character_id' => $character->character_id, 'system_id' => 30000142,
            'sig_id' => 'ESC-ABC', 'sig_group' => 'Escalation', 'name' => 'Serpentis Phi',
            'status' => 'active', 'first_seen' => now(), 'last_seen' => now(),
            'expires_at' => now()->addHours(3),
        ]);

        $this->app->make(AlertService::class)->refresh($character);

        $alert = Alert::where('type', 'escalation')->first();
        $this->assertNotNull($alert);
        $this->assertSame('urgent', $alert->severity);
        $this->assertStringContainsString('Serpentis Phi', $alert->message);
    }

    public function test_undercut_orders_alert(): void
    {
        $character = Character::factory()->create();
        DB::table('character_skill_queue')->insert([
            'character_id' => $character->character_id, 'position' => 0, 'skill_id' => 1,
            'finished_level' => 5, 'finish_date' => now()->addMonth(),
        ]);

        $this->mock(UndercutService::class)->shouldReceive('check')->andReturn(collect([
            (object) ['undercut' => true], (object) ['undercut' => true], (object) ['undercut' => false],
        ]));

        $this->app->make(AlertService::class)->refresh($character);

        $alert = Alert::where('type', 'undercut')->first();
        $this->assertStringContainsString('2 of your market orders', $alert->message);
    }
}
