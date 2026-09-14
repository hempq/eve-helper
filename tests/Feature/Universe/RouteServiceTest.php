<?php

namespace Tests\Feature\Universe;

use App\Services\Universe\RouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RouteServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Diamond graph: 1 -2- 3 is the long high-sec path (via 2),
     * 1 -4- 3 is shorter but 4 is low-sec.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $systems = [
            [1, 'Origo', 0.9], [2, 'SafeMid', 0.7], [3, 'Target', 0.9],
            [4, 'DangerMid', 0.3], [5, 'Island', 1.0],
        ];
        foreach ($systems as [$id, $name, $security]) {
            DB::table('solar_systems')->insert([
                'system_id' => $id, 'constellation_id' => 1, 'region_id' => 1,
                'name' => $name, 'security' => $security,
            ]);
        }

        $edges = [[1, 2], [2, 3], [1, 4], [4, 3]];
        foreach ($edges as [$a, $b]) {
            DB::table('system_jumps')->insert([
                ['from_system_id' => $a, 'to_system_id' => $b],
                ['from_system_id' => $b, 'to_system_id' => $a],
            ]);
        }
    }

    public function test_prefer_safer_avoids_lowsec(): void
    {
        $route = $this->app->make(RouteService::class)->route(1, 3, preferSafer: true);

        $this->assertSame([1, 2, 3], $route);
    }

    public function test_shortest_goes_through_lowsec_when_equal_length(): void
    {
        // Both paths are 2 jumps; without the penalty either is acceptable —
        // remove the safe edge to force the low-sec one.
        DB::table('system_jumps')->where('from_system_id', 2)->orWhere('to_system_id', 2)->delete();

        $service = $this->app->make(RouteService::class);

        $this->assertSame([1, 4, 3], $service->route(1, 3, preferSafer: false));
        // Safer routing still uses it when there is no alternative.
        $this->assertSame([1, 4, 3], $service->route(1, 3, preferSafer: true));
    }

    public function test_unreachable_returns_null(): void
    {
        $this->assertNull($this->app->make(RouteService::class)->route(1, 5));
    }

    public function test_same_system_is_zero_jumps(): void
    {
        $this->assertSame(0, $this->app->make(RouteService::class)->jumps(1, 1));
    }
}
