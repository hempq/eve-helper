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
    private const BASELINE_HOURS = 168;

    // Anomalies respawn in minutes, so "recent" means the last few hours.
    private const RECENT_HOURS = 6;

    /** ~5 days of hourly snapshots. */
    private const MIN_BASELINE_SNAPSHOTS = 120;

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
     * Exponentially-weighted recent NPC activity (half-life ~2h). Combat
     * anomalies respawn within minutes (constellation-wide batch respawn),
     * so whether a pocket has sites RIGHT NOW only depends on the last
     * couple of hours — a system farmed out yesterday is long since full
     * again. Systems absent from a snapshot count as zero for that hour.
     *
     * @return array{npc: array<int, float>, snapshots: int}
     */
    public function recentEwma(int $windowHours = 12, float $halfLifeHours = 2.0): array
    {
        $since = now()->subHours($windowHours);

        $rows = DB::table('system_activity')
            ->where('recorded_at', '>=', $since)
            ->get(['system_id', 'npc_kills', 'recorded_at']);

        if ($rows->isEmpty()) {
            return ['npc' => [], 'snapshots' => 0];
        }

        $weights = [];
        foreach ($rows->pluck('recorded_at')->unique() as $at) {
            $ageHours = CarbonImmutable::parse($at)->diffInMinutes(now(), true) / 60;
            $weights[$at] = 0.5 ** ($ageHours / $halfLifeHours);
        }
        $totalWeight = array_sum($weights);

        $npc = [];
        foreach ($rows as $row) {
            $id = (int) $row->system_id;
            $npc[$id] = ($npc[$id] ?? 0.0) + $row->npc_kills * $weights[$row->recorded_at];
        }

        return [
            'npc' => array_map(fn (float $sum) => $sum / $totalWeight, $npc),
            'snapshots' => count($weights),
        ];
    }

    /**
     * Backlog/surge trends: the week-long NPC-kill baseline versus the last
     * ~6 hours, with the recent window normalized by the global activity
     * level (the hour-of-day player wave lifts and drops every system
     * together, so the global ratio is the diurnal correction). A system far
     * below its own baseline right now has anomalies piling up (backlog); one
     * far above it is being farmed right now.
     *
     * @return array{ready: bool, systems: array<int, object{baseline: float,
     *   recentNorm: float, ratio: float}>}
     */
    public function trends(): array
    {
        $base = $this->averages(self::BASELINE_HOURS);

        // Need most of a week of snapshots for a trustworthy baseline.
        if ($base['snapshots'] < self::MIN_BASELINE_SNAPSHOTS) {
            return ['ready' => false, 'systems' => []];
        }

        $recent = $this->averages(self::RECENT_HOURS);

        if ($recent['snapshots'] === 0) {
            return ['ready' => false, 'systems' => []];
        }

        // Diurnal correction, clamped so a dead quiet (or booming) cluster
        // hour cannot blow the ratio up.
        $globalBase = array_sum($base['npc']);
        $factor = $globalBase > 0
            ? max(0.25, min(4.0, array_sum($recent['npc']) / $globalBase))
            : 1.0;

        $systems = [];

        foreach ($base['npc'] as $id => $baseline) {
            if ($baseline <= 0) {
                continue;
            }

            $recentNorm = ($recent['npc'][$id] ?? 0.0) / $factor;

            $systems[$id] = (object) [
                'baseline' => $baseline,
                'recentNorm' => $recentNorm,
                'ratio' => $recentNorm / $baseline,
            ];
        }

        return ['ready' => true, 'systems' => $systems];
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
