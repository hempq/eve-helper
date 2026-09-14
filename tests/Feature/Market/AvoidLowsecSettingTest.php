<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\HubComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvoidLowsecSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_route_respects_the_global_highsec_only_setting(): void
    {
        DB::table('item_types')->insert([
            'type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'volume' => 0.01, 'published' => true,
        ]);

        // Origin(1) - LowMid(2, 0.3) - Jita: the only path crosses low-sec.
        DB::table('solar_systems')->insert([
            ['system_id' => 1, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Origin', 'security' => 0.9],
            ['system_id' => 2, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'LowMid', 'security' => 0.3],
            ['system_id' => 30000142, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Jita', 'security' => 0.9],
        ]);
        foreach ([[1, 2], [2, 30000142]] as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }

        Http::fake([
            'market.fuzzwork.co.uk/*station=60003760*' => Http::response([
                '34' => ['buy' => ['percentile' => 4.0], 'sell' => ['percentile' => 5.0]],
            ]),
            'market.fuzzwork.co.uk/*' => Http::response([]),
        ]);

        $service = $this->app->make(HubComparisonService::class);

        // High-sec only (default): Jita is unreachable.
        $strict = Character::factory()->create();
        $result = $service->compare($strict, [34 => 100], originSystemId: 1);
        $this->assertNull(collect($result['options'])->firstWhere('systemName', 'Jita')->jumps);

        // Allowing low-sec finds the 2-jump route.
        $brave = Character::factory()->create(['avoid_lowsec' => false]);
        $result = $service->compare($brave, [34 => 100], originSystemId: 1);
        $this->assertSame(2, collect($result['options'])->firstWhere('systemName', 'Jita')->jumps);
    }
}
