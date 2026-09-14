<?php

namespace App\Services\Farm;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists the hourly ESI activity snapshots so scoring can average over a
 * window instead of trusting one noisy hour.
 */
class ActivityRecorder
{
    public function __construct(private readonly ActivitySource $source) {}

    /**
     * Records one snapshot; skipped when the newest stored snapshot is
     * younger than ~50 minutes (ESI refreshes hourly).
     *
     * @return int rows written (0 when skipped)
     */
    public function record(): int
    {
        $latest = DB::table('system_activity')->max('recorded_at');

        if ($latest !== null && CarbonImmutable::parse($latest)->gt(now()->subMinutes(50))) {
            return 0;
        }

        [$npc, $ship, $pod] = $this->source->killActivity();
        $jumps = $this->source->jumpActivity();

        $now = CarbonImmutable::now()->startOfMinute();
        $systemIds = array_unique([...array_keys($npc), ...array_keys($jumps)]);

        $rows = array_map(fn (int $id) => [
            'system_id' => $id,
            'npc_kills' => $npc[$id] ?? 0,
            'ship_kills' => $ship[$id] ?? 0,
            'pod_kills' => $pod[$id] ?? 0,
            'ship_jumps' => $jumps[$id] ?? 0,
            'recorded_at' => $now,
        ], $systemIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('system_activity')->upsert($chunk, ['system_id', 'recorded_at']);
        }

        // Keep a rolling week; older snapshots stop adding signal.
        DB::table('system_activity')->where('recorded_at', '<', now()->subDays(7))->delete();

        return count($rows);
    }

    /**
     * Averages over the lookback window, keyed by system id.
     *
     * @return array{npc: array<int,float>, players: array<int,float>, jumps: array<int,float>, snapshots: int}
     */
    public function averages(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $snapshots = (int) DB::table('system_activity')
            ->where('recorded_at', '>=', $since)
            ->distinct()->count('recorded_at');

        if ($snapshots === 0) {
            return ['npc' => [], 'players' => [], 'jumps' => [], 'snapshots' => 0];
        }

        $rows = DB::table('system_activity')
            ->where('recorded_at', '>=', $since)
            ->selectRaw('system_id, SUM(npc_kills) as npc, SUM(ship_kills + pod_kills) as players, SUM(ship_jumps) as jumps')
            ->groupBy('system_id')
            ->get();

        $npc = $players = $jumps = [];

        foreach ($rows as $row) {
            $id = (int) $row->system_id;
            // Divide by snapshot count: systems absent from a snapshot had
            // zero activity that hour, so the denominator must be global.
            $npc[$id] = $row->npc / $snapshots;
            $players[$id] = $row->players / $snapshots;
            $jumps[$id] = $row->jumps / $snapshots;
        }

        return ['npc' => $npc, 'players' => $players, 'jumps' => $jumps, 'snapshots' => $snapshots];
    }
}
