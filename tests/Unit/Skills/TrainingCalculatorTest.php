<?php

namespace Tests\Unit\Skills;

use App\Services\Skills\TrainingCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrainingCalculatorTest extends TestCase
{
    private TrainingCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new TrainingCalculator;
    }

    public function test_cumulative_sp_matches_known_rank1_values(): void
    {
        // Canonical table from EVE University.
        $this->assertSame(0, $this->calc->cumulativeSp(1, 0));
        $this->assertSame(250, $this->calc->cumulativeSp(1, 1));
        $this->assertSame(1414, $this->calc->cumulativeSp(1, 2));
        $this->assertSame(8000, $this->calc->cumulativeSp(1, 3));
        $this->assertSame(45255, $this->calc->cumulativeSp(1, 4));
        $this->assertSame(256000, $this->calc->cumulativeSp(1, 5));
    }

    public function test_cumulative_sp_scales_linearly_with_rank(): void
    {
        // Rank 8 (e.g. Amarr Battleship) level V.
        $this->assertSame(2048000, $this->calc->cumulativeSp(8, 5));
        $this->assertSame(2000, $this->calc->cumulativeSp(8, 1));
    }

    public function test_sp_between_levels(): void
    {
        $this->assertSame(248000, $this->calc->spBetweenLevels(1, 3, 5)); // 256000 - 8000
        $this->assertSame(210745, $this->calc->spBetweenLevels(1, 4, 5));
    }

    public function test_rejects_invalid_level(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->cumulativeSp(1, 6);
    }

    public function test_sp_per_minute_omega_and_alpha(): void
    {
        // Default character: 20 primary, 20 secondary => 30 SP/min.
        $this->assertSame(30.0, $this->calc->spPerMinute(20, 20));
        // Fully optimized: 27 + 21/2... classic max: 27 primary, 21 secondary isn't
        // the canonical example; use +5 implants case 32/27 => 45.5.
        $this->assertSame(45.5, $this->calc->spPerMinute(32, 27));
        // Alpha rate is half.
        $this->assertSame(15.0, $this->calc->spPerMinute(20, 20, omega: false));
    }

    public function test_minutes_to_train(): void
    {
        // 256000 SP at 30 SP/min.
        $this->assertEqualsWithDelta(8533.33, $this->calc->minutesToTrain(256000, 30.0), 0.01);

        $this->expectException(InvalidArgumentException::class);
        $this->calc->minutesToTrain(1000, 0.0);
    }
}
