<?php

namespace Tests\Feature\Farm;

use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Farm\ActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEsi(int $npcKills): void
    {
        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')->with('/universe/system_kills')->andReturn(new EsiResponse([
            ['system_id' => 1, 'npc_kills' => $npcKills, 'ship_kills' => 1, 'pod_kills' => 0],
        ], $expires));
        $esi->shouldReceive('get')->with('/universe/system_jumps')->andReturn(new EsiResponse([
            ['system_id' => 1, 'ship_jumps' => 40],
            ['system_id' => 2, 'ship_jumps' => 10],
        ], $expires));
    }

    public function test_records_snapshot_and_skips_while_fresh(): void
    {
        $this->fakeEsi(100);
        $recorder = $this->app->make(ActivityRecorder::class);

        $this->assertSame(2, $recorder->record()); // systems 1 and 2
        $this->assertSame(0, $recorder->record()); // guard: still fresh

        $row = DB::table('system_activity')->where('system_id', 1)->first();
        $this->assertSame(100, (int) $row->npc_kills);
        $this->assertSame(40, (int) $row->ship_jumps);
    }

    public function test_averages_treat_missing_systems_as_zero_hours(): void
    {
        $recorder = $this->app->make(ActivityRecorder::class);

        // Two snapshots an hour apart; system 2 only appears in the second.
        DB::table('system_activity')->insert([
            ['system_id' => 1, 'npc_kills' => 100, 'ship_kills' => 0, 'pod_kills' => 0, 'ship_jumps' => 40, 'recorded_at' => now()->subHours(2)],
            ['system_id' => 1, 'npc_kills' => 200, 'ship_kills' => 2, 'pod_kills' => 0, 'ship_jumps' => 60, 'recorded_at' => now()->subHour()],
            ['system_id' => 2, 'npc_kills' => 50, 'ship_kills' => 0, 'pod_kills' => 0, 'ship_jumps' => 10, 'recorded_at' => now()->subHour()],
        ]);

        $averages = $recorder->averages(24);

        $this->assertSame(2, $averages['snapshots']);
        $this->assertEqualsWithDelta(150.0, $averages['npc'][1], 0.001);  // (100+200)/2
        $this->assertEqualsWithDelta(25.0, $averages['npc'][2], 0.001);   // 50/2 — absent hour counts as zero
        $this->assertEqualsWithDelta(1.0, $averages['players'][1], 0.001);
        $this->assertEqualsWithDelta(50.0, $averages['jumps'][1], 0.001);
    }
}
