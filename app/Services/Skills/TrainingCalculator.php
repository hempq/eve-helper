<?php

namespace App\Services\Skills;

use InvalidArgumentException;

/**
 * Skill training math. Sources: EVE University "Skills and learning".
 *
 *   SP needed for level L (cumulative): 250 × rank × √32^(L−1)
 *   Training rate (Omega): primary + secondary/2 SP per minute; Alpha: half.
 */
class TrainingCalculator
{
    /**
     * Total (cumulative) SP required to have a skill at $level.
     */
    public function cumulativeSp(int $rank, int $level): int
    {
        if ($level < 0 || $level > 5) {
            throw new InvalidArgumentException("Skill level must be 0-5, got {$level}.");
        }

        if ($level === 0) {
            return 0;
        }

        return (int) round(250 * $rank * (2 ** (2.5 * ($level - 1))));
    }

    /**
     * SP needed to go from $fromLevel to $toLevel.
     */
    public function spBetweenLevels(int $rank, int $fromLevel, int $toLevel): int
    {
        return $this->cumulativeSp($rank, $toLevel) - $this->cumulativeSp($rank, $fromLevel);
    }

    public function spPerMinute(int $primaryAttribute, int $secondaryAttribute, bool $omega = true): float
    {
        $rate = $primaryAttribute + $secondaryAttribute / 2;

        return $omega ? $rate : $rate / 2;
    }

    public function minutesToTrain(int $sp, float $spPerMinute): float
    {
        if ($spPerMinute <= 0) {
            throw new InvalidArgumentException('SP/minute must be positive.');
        }

        return $sp / $spPerMinute;
    }
}
