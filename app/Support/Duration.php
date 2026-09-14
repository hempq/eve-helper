<?php

namespace App\Support;

/**
 * Formats training durations the way EVE players read them: "23d 14h 05m".
 */
final class Duration
{
    public static function minutes(float $minutes): string
    {
        $totalMinutes = (int) round($minutes);

        $days = intdiv($totalMinutes, 1440);
        $hours = intdiv($totalMinutes % 1440, 60);
        $mins = $totalMinutes % 60;

        return match (true) {
            $days > 0 => sprintf('%dd %dh %02dm', $days, $hours, $mins),
            $hours > 0 => sprintf('%dh %02dm', $hours, $mins),
            default => sprintf('%dm', $mins),
        };
    }
}
