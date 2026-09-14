<?php

namespace App\Services\Skills;

/**
 * Multi-remap planning: the training sequence (in actual training order) is
 * cut into segments at changes of the dominant primary attribute, each
 * segment at least ~30 days long (remaps are a scarce yearly resource), and
 * every segment gets its own brute-force optimal remap. Worth it only when
 * the summed segment times beat the single-remap optimum.
 */
class MultiRemapPlanner
{
    /** A remap is too precious for segments shorter than this. */
    public const MIN_SEGMENT_DAYS = 30;

    private const MIN_SEGMENT_MINUTES = self::MIN_SEGMENT_DAYS * 1440;

    public function __construct(private readonly RemapOptimizer $optimizer) {}

    /**
     * @param  list<object{primaryAttribute: string, secondaryAttribute: string, sp: int}>  $steps
     *         the plan in training order, NOT aggregated into buckets
     * @return ?MultiRemapPlan null when the sequence cannot support more than
     *         one worthwhile segment
     */
    public function plan(array $steps, AttributeSet $bonuses, int $maxSegments = 4): ?MultiRemapPlan
    {
        $steps = array_values(array_filter($steps, fn ($s) => $s->sp > 0));

        if ($steps === []) {
            return null;
        }

        // Durations during segmentation use a neutral balanced map — actual
        // per-segment attributes are only known after optimization.
        $nominal = (new AttributeSet(19, 20, 20, 20, 20))->add($bonuses);

        $runs = $this->mergedRuns($this->initialRuns($steps), $nominal, $maxSegments);

        if (count($runs) < 2) {
            return null; // one segment = the plain single remap
        }

        $segments = [];
        $cursor = 0.0;

        foreach ($runs as $run) {
            $buckets = $this->buckets($run);
            $result = $this->optimizer->optimize($buckets, $bonuses);

            $segments[] = new RemapSegment(
                buckets: $buckets,
                dominantPrimary: $this->dominantPrimary($run),
                sp: array_sum(array_map(fn ($s) => $s->sp, $run)),
                base: $result->baseAttributes,
                minutes: $result->minutes,
                startMinutes: $cursor,
            );
            $cursor += $result->minutes;
        }

        $single = $this->optimizer->optimize($this->buckets($steps), $bonuses);

        return new MultiRemapPlan(
            segments: $segments,
            totalMinutes: $cursor,
            singleRemapMinutes: $single->minutes,
        );
    }

    /**
     * Contiguous runs sharing a primary attribute.
     *
     * @param  list<object>  $steps
     * @return list<list<object>>
     */
    private function initialRuns(array $steps): array
    {
        $runs = [];
        $current = [];

        foreach ($steps as $step) {
            if ($current !== [] && end($current)->primaryAttribute !== $step->primaryAttribute) {
                $runs[] = $current;
                $current = [];
            }
            $current[] = $step;
        }
        $runs[] = $current;

        return $runs;
    }

    /**
     * Repeatedly merge the shortest run into its shorter neighbour until
     * every run lasts at least MIN_SEGMENT_DAYS and the count fits the
     * remap budget.
     *
     * @param  list<list<object>>  $runs
     * @return list<list<object>>
     */
    private function mergedRuns(array $runs, AttributeSet $nominal, int $maxSegments): array
    {
        while (count($runs) > 1) {
            $minutes = array_map(
                fn (array $run) => $this->optimizer->minutesFor($this->buckets($run), $nominal),
                $runs,
            );

            $shortest = array_search(min($minutes), $minutes, true);

            if ($minutes[$shortest] >= self::MIN_SEGMENT_MINUTES && count($runs) <= $maxSegments) {
                break;
            }

            // Merge into the shorter neighbour so long segments stay intact.
            $left = $shortest - 1;
            $right = $shortest + 1;
            $into = match (true) {
                $left < 0 => $right,
                $right >= count($runs) => $left,
                default => $minutes[$left] <= $minutes[$right] ? $left : $right,
            };

            $merged = $into < $shortest
                ? [...$runs[$into], ...$runs[$shortest]]
                : [...$runs[$shortest], ...$runs[$into]];

            array_splice($runs, min($into, $shortest), 2, [$merged]);
        }

        return $runs;
    }

    /**
     * @param  list<object>  $steps
     * @return list<TrainingBucket>
     */
    private function buckets(array $steps): array
    {
        $bucketSp = [];

        foreach ($steps as $step) {
            $key = $step->primaryAttribute.'|'.$step->secondaryAttribute;
            $bucketSp[$key] = ($bucketSp[$key] ?? 0) + $step->sp;
        }

        $buckets = [];
        foreach ($bucketSp as $key => $sp) {
            [$primary, $secondary] = explode('|', $key);
            $buckets[] = new TrainingBucket($primary, $secondary, $sp);
        }

        return $buckets;
    }

    /** @param  list<object>  $steps */
    private function dominantPrimary(array $steps): string
    {
        $spByPrimary = [];
        foreach ($steps as $step) {
            $spByPrimary[$step->primaryAttribute] = ($spByPrimary[$step->primaryAttribute] ?? 0) + $step->sp;
        }
        arsort($spByPrimary);

        return array_key_first($spByPrimary);
    }
}
