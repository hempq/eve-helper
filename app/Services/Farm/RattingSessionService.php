<?php

namespace App\Services\Farm;

use App\Models\Character;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns bounty_prizes wallet-journal rows (one row per ~20-minute ratting
 * tick, tagged with the system) into farming sessions with ISK/hour.
 */
class RattingSessionService
{
    /** Ticks further apart than this start a new session. */
    private const SESSION_GAP_MINUTES = 45;

    /** A lone tick still represents ~20 minutes of ratting. */
    private const TICK_MINUTES = 20;

    /**
     * @return Collection<int, object{start: CarbonImmutable, end: CarbonImmutable,
     *   isk: float, ticks: int, systems: list<string>, hours: float, iskPerHour: float}>
     *   newest session first
     */
    public function sessions(Character $character, int $days = 30): Collection
    {
        $rows = DB::table('wallet_journal')
            ->where('character_id', $character->character_id)
            ->where('ref_type', 'bounty_prizes')
            ->where('date', '>=', now()->subDays($days))
            ->orderBy('date')
            ->get(['date', 'amount', 'context_id', 'context_id_type']);

        if ($rows->isEmpty()) {
            return collect();
        }

        $systemNames = DB::table('solar_systems')
            ->whereIn('system_id', $rows->where('context_id_type', 'system_id')->pluck('context_id')->unique())
            ->pluck('name', 'system_id');

        $sessions = [];
        $current = null;

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->date);

            if ($current !== null && $date->diffInMinutes($current['end'], true) > self::SESSION_GAP_MINUTES) {
                $sessions[] = $current;
                $current = null;
            }

            $current ??= ['start' => $date, 'end' => $date, 'isk' => 0.0, 'ticks' => 0, 'systems' => []];

            $current['end'] = $date;
            $current['isk'] += (float) $row->amount;
            $current['ticks']++;

            if ($row->context_id_type === 'system_id') {
                $name = $systemNames[$row->context_id] ?? "#{$row->context_id}";
                $current['systems'][$name] = true;
            }
        }

        $sessions[] = $current;

        return collect($sessions)
            ->map(function (array $s) {
                // The first tick pays for the ~20 minutes before it.
                $hours = ($s['start']->diffInMinutes($s['end'], true) + self::TICK_MINUTES) / 60;

                return (object) [
                    'start' => $s['start'],
                    'end' => $s['end'],
                    'isk' => $s['isk'],
                    'ticks' => $s['ticks'],
                    'systems' => array_keys($s['systems']),
                    'hours' => round($hours, 2),
                    'iskPerHour' => $hours > 0 ? $s['isk'] / $hours : 0.0,
                ];
            })
            ->sortByDesc('start')
            ->values();
    }

    /**
     * Daily bounty totals for a simple bar chart.
     *
     * @return Collection<string, float> date (Y-m-d) => ISK
     */
    public function dailyTotals(Character $character, int $days = 14): Collection
    {
        return DB::table('wallet_journal')
            ->where('character_id', $character->character_id)
            ->where('ref_type', 'bounty_prizes')
            ->where('date', '>=', now()->subDays($days))
            ->selectRaw('DATE(date) as day, SUM(amount) as isk')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('isk', 'day')
            ->map(fn ($isk) => (float) $isk);
    }
}
