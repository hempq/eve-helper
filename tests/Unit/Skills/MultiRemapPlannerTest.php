<?php

namespace Tests\Unit\Skills;

use App\Services\Skills\AttributeSet;
use App\Services\Skills\MultiRemapPlanner;
use App\Services\Skills\RemapOptimizer;
use PHPUnit\Framework\TestCase;

class MultiRemapPlannerTest extends TestCase
{
    private function step(string $primary, string $secondary, int $sp): object
    {
        return (object) ['primaryAttribute' => $primary, 'secondaryAttribute' => $secondary, 'sp' => $sp];
    }

    private function planner(): MultiRemapPlanner
    {
        return new MultiRemapPlanner(new RemapOptimizer);
    }

    public function test_splits_at_dominant_primary_change_and_beats_single_remap(): void
    {
        // Two long blocks (~3M SP each is well over 30 days at any map) with
        // opposing primaries — the classic case where one remap must
        // compromise but two remaps can each go all-in.
        $plan = $this->planner()->plan([
            $this->step('perception', 'willpower', 3_000_000),
            $this->step('intelligence', 'memory', 3_000_000),
        ], new AttributeSet(0, 0, 0, 0, 0));

        $this->assertNotNull($plan);
        $this->assertCount(2, $plan->segments);

        // Each segment maxes its own primary instead of compromising.
        $this->assertSame(27, $plan->segments[0]->base->perception);
        $this->assertSame(27, $plan->segments[1]->base->intelligence);
        $this->assertSame('perception', $plan->segments[0]->dominantPrimary);
        $this->assertSame('intelligence', $plan->segments[1]->dominantPrimary);

        $this->assertGreaterThan(0, $plan->savedMinutes());
        $this->assertEqualsWithDelta(
            $plan->segments[0]->minutes,
            $plan->segments[1]->startMinutes,
            0.001,
        );
    }

    public function test_short_tail_is_merged_into_one_segment(): void
    {
        // The second block is far below the 30-day floor, so it merges back
        // and a single remap covers everything -> no multi-remap plan.
        $plan = $this->planner()->plan([
            $this->step('perception', 'willpower', 3_000_000),
            $this->step('intelligence', 'memory', 100_000),
        ], new AttributeSet(0, 0, 0, 0, 0));

        $this->assertNull($plan);
    }

    public function test_segment_count_respects_the_cap(): void
    {
        $steps = [];
        foreach (['perception', 'intelligence', 'willpower', 'memory', 'perception', 'intelligence'] as $primary) {
            $steps[] = $this->step($primary, 'willpower', 3_000_000);
        }

        $plan = $this->planner()->plan($steps, new AttributeSet(0, 0, 0, 0, 0), maxSegments: 3);

        $this->assertNotNull($plan);
        $this->assertLessThanOrEqual(3, count($plan->segments));
    }

    public function test_empty_steps_return_null(): void
    {
        $this->assertNull($this->planner()->plan([], new AttributeSet(0, 0, 0, 0, 0)));
    }
}
