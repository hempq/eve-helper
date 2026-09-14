<?php

namespace App\Services\Skills;

use InvalidArgumentException;

/**
 * Finds the neural remap that minimizes training time for a set of training
 * buckets — the EVEMon approach: brute-force every valid attribute
 * distribution (each attribute 17-27, all summing to 99; at most 11^4 = 14641
 * candidates) and simulate each one.
 */
class RemapOptimizer
{
    public const MIN_ATTRIBUTE = 17;

    public const MAX_ATTRIBUTE = 27;

    public const TOTAL_POINTS = 99;

    /**
     * @param  list<TrainingBucket>  $buckets
     * @param  AttributeSet  $bonuses  implant (and booster) bonuses added on
     *                                 top of every candidate base distribution
     */
    public function optimize(array $buckets, AttributeSet $bonuses): RemapResult
    {
        if ($buckets === []) {
            throw new InvalidArgumentException('Cannot optimize an empty training plan.');
        }

        $bestMinutes = INF;
        $bestBase = null;

        $min = self::MIN_ATTRIBUTE;
        $max = self::MAX_ATTRIBUTE;

        for ($cha = $min; $cha <= $max; $cha++) {
            for ($int = $min; $int <= $max; $int++) {
                for ($mem = $min; $mem <= $max; $mem++) {
                    for ($per = $min; $per <= $max; $per++) {
                        $wil = self::TOTAL_POINTS - $cha - $int - $mem - $per;

                        if ($wil < $min || $wil > $max) {
                            continue;
                        }

                        $attributes = [
                            'charisma' => $cha + $bonuses->charisma,
                            'intelligence' => $int + $bonuses->intelligence,
                            'memory' => $mem + $bonuses->memory,
                            'perception' => $per + $bonuses->perception,
                            'willpower' => $wil + $bonuses->willpower,
                        ];

                        $minutes = $this->minutesFor($buckets, $attributes);

                        if ($minutes < $bestMinutes) {
                            $bestMinutes = $minutes;
                            $bestBase = new AttributeSet($cha, $int, $mem, $per, $wil);
                        }
                    }
                }
            }
        }

        return new RemapResult($bestBase, $bestMinutes);
    }

    /**
     * Simulate total training time for buckets under given effective attributes.
     *
     * @param  list<TrainingBucket>  $buckets
     * @param  array<string, int>|AttributeSet  $attributes
     */
    public function minutesFor(array $buckets, array|AttributeSet $attributes): float
    {
        if ($attributes instanceof AttributeSet) {
            $attributes = $attributes->toArray();
        }

        $minutes = 0.0;

        foreach ($buckets as $bucket) {
            $rate = $attributes[$bucket->primaryAttribute] + $attributes[$bucket->secondaryAttribute] / 2;
            $minutes += $bucket->sp / $rate;
        }

        return $minutes;
    }
}
