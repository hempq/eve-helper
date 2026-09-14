<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Character;
use App\Services\Alerts\AlertService;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Market\UndercutService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloneAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(UndercutService::class)->shouldReceive('check')->andReturn(collect());
    }

    public function test_jump_clone_off_cooldown_raises_info_alert(): void
    {
        $character = Character::factory()->create([
            'last_clone_jump_date' => now()->subHours(30), // 24h cooldown passed
        ]);
        DB::table('character_clones')->insert([
            'character_id' => $character->character_id, 'jump_clone_id' => 1,
            'name' => null, 'location_id' => 60003760, 'location_type' => 'station',
            'implants' => '[]',
        ]);

        $this->app->make(AlertService::class)->refresh($character);

        $alert = Alert::where('character_id', $character->character_id)
            ->where('dedupe_key', 'jump_clone_ready')->first();
        $this->assertNotNull($alert);
        $this->assertSame('info', $alert->severity);
    }

    public function test_old_cooldown_expiry_no_longer_nags(): void
    {
        $character = Character::factory()->create([
            'last_clone_jump_date' => now()->subDays(10),
        ]);
        DB::table('character_clones')->insert([
            'character_id' => $character->character_id, 'jump_clone_id' => 1,
            'name' => null, 'location_id' => 60003760, 'location_type' => 'station',
            'implants' => '[]',
        ]);

        $this->app->make(AlertService::class)->refresh($character);

        $this->assertNull(Alert::where('dedupe_key', 'jump_clone_ready')->first());
    }

    public function test_distant_death_clone_warns(): void
    {
        // Chain of 12 systems; pilot at one end, death clone station at the other.
        for ($i = 1; $i <= 12; $i++) {
            DB::table('solar_systems')->insert([
                'system_id' => $i, 'constellation_id' => 1, 'region_id' => 1,
                'name' => "Sys{$i}", 'security' => 0.5,
            ]);
            if ($i > 1) {
                DB::table('system_jumps')->insert([
                    ['from_system_id' => $i - 1, 'to_system_id' => $i],
                    ['from_system_id' => $i, 'to_system_id' => $i - 1],
                ]);
            }
        }
        DB::table('stations')->insert([
            'station_id' => 61000001, 'system_id' => 12, 'name' => 'Far Home',
        ]);

        $character = Character::factory()->create([
            'home_location_id' => 61000001,
            'home_location_type' => 'station',
        ]);

        $expires = CarbonImmutable::now()->addHour();
        $this->mock(EsiClientInterface::class)
            ->shouldReceive('get')
            ->with("/characters/{$character->character_id}/location", [], \Mockery::type(Character::class))
            ->andReturn(new EsiResponse(['solar_system_id' => 1], $expires));

        $this->app->make(AlertService::class)->refresh($character);

        $alert = Alert::where('dedupe_key', 'death_clone_far')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('11 jumps', $alert->message);
        $this->assertStringContainsString('Sys12', $alert->message);
    }

    public function test_nearby_death_clone_stays_silent(): void
    {
        DB::table('solar_systems')->insert([
            'system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Here', 'security' => 0.5,
        ]);
        DB::table('stations')->insert([
            'station_id' => 61000001, 'system_id' => 1, 'name' => 'Home',
        ]);

        $character = Character::factory()->create([
            'home_location_id' => 61000001,
            'home_location_type' => 'station',
        ]);

        $expires = CarbonImmutable::now()->addHour();
        $this->mock(EsiClientInterface::class)
            ->shouldReceive('get')
            ->andReturn(new EsiResponse(['solar_system_id' => 1], $expires));

        $this->app->make(AlertService::class)->refresh($character);

        $this->assertNull(Alert::where('dedupe_key', 'death_clone_far')->first());
    }
}
