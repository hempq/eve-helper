<?php

namespace Tests\Unit\Skills;

use App\Services\Skills\AttributeSet;
use App\Services\Skills\RemapOptimizer;
use App\Services\Skills\TrainingBucket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RemapOptimizerTest extends TestCase
{
    private RemapOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new RemapOptimizer;
    }

    public function test_single_pair_plan_maxes_primary_then_secondary(): void
    {
        $buckets = [new TrainingBucket('perception', 'willpower', 1_000_000)];

        $result = $this->optimizer->optimize($buckets, AttributeSet::zero());
        $base = $result->baseAttributes;

        // 14 free points on top of all-17: primary to 27 (+10), rest to secondary.
        $this->assertSame(27, $base->perception);
        $this->assertSame(21, $base->willpower);
        $this->assertSame(17, $base->charisma);
        $this->assertSame(17, $base->intelligence);
        $this->assertSame(17, $base->memory);
        $this->assertSame(99, $base->total());

        // 1M SP at 27 + 21/2 = 37.5 SP/min.
        $this->assertEqualsWithDelta(1_000_000 / 37.5, $result->minutes, 0.01);
    }

    public function test_mixed_plan_weights_toward_the_heavier_pair(): void
    {
        $buckets = [
            new TrainingBucket('intelligence', 'memory', 5_000_000),
            new TrainingBucket('perception', 'willpower', 100_000),
        ];

        $base = $this->optimizer->optimize($buckets, AttributeSet::zero())->baseAttributes;

        // The int/mem block dominates; perception must stay near minimum.
        $this->assertSame(27, $base->intelligence);
        $this->assertGreaterThanOrEqual(20, $base->memory);
        $this->assertLessThanOrEqual(18, $base->charisma);
    }

    public function test_implant_bonuses_shift_the_effective_rate_not_the_base(): void
    {
        $buckets = [new TrainingBucket('perception', 'willpower', 750_000)];
        $bonuses = new AttributeSet(0, 0, 0, 5, 5);

        $result = $this->optimizer->optimize($buckets, $bonuses);

        // Base distribution is unchanged, but the simulated time uses 32/26.
        $this->assertSame(27, $result->baseAttributes->perception);
        $this->assertEqualsWithDelta(750_000 / (32 + 26 / 2), $result->minutes, 0.01);
    }

    public function test_every_candidate_distribution_is_valid(): void
    {
        $buckets = [new TrainingBucket('charisma', 'intelligence', 10_000)];
        $base = $this->optimizer->optimize($buckets, AttributeSet::zero())->baseAttributes;

        foreach ($base->toArray() as $value) {
            $this->assertGreaterThanOrEqual(RemapOptimizer::MIN_ATTRIBUTE, $value);
            $this->assertLessThanOrEqual(RemapOptimizer::MAX_ATTRIBUTE, $value);
        }
        $this->assertSame(RemapOptimizer::TOTAL_POINTS, $base->total());
    }

    public function test_empty_plan_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->optimizer->optimize([], AttributeSet::zero());
    }
}
