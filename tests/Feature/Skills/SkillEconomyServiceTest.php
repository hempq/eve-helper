<?php

namespace Tests\Feature\Skills;

use App\Models\Character;
use App\Services\Skills\SkillEconomyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkillEconomyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakePrices(float $largeSell, float $largeBuy, float $extractor, float $smallSell = 0): void
    {
        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '40520' => ['buy' => ['percentile' => $largeBuy], 'sell' => ['percentile' => $largeSell]],
                '45635' => ['buy' => ['percentile' => 0], 'sell' => ['percentile' => $smallSell]],
                '40519' => ['buy' => ['percentile' => 0], 'sell' => ['percentile' => $extractor]],
            ]),
        ]);
    }

    public function test_injector_yield_brackets(): void
    {
        $svc = $this->app->make(SkillEconomyService::class);
        $this->assertSame(500_000, $svc->largeInjectorYield(3_000_000));
        $this->assertSame(400_000, $svc->largeInjectorYield(20_000_000));
        $this->assertSame(300_000, $svc->largeInjectorYield(60_000_000));
        $this->assertSame(150_000, $svc->largeInjectorYield(100_000_000));
    }

    public function test_analyze_cost_per_sp_and_days_saved(): void
    {
        $character = Character::factory()->create(['total_sp' => 3_500_000]);
        $this->fakePrices(largeSell: 900_000_000, largeBuy: 800_000_000, extractor: 400_000_000);

        // 45 SP/min = 2700 SP/h.
        $result = $this->app->make(SkillEconomyService::class)->analyze($character, salesTaxRate: 0.0337, spPerHour: 2700);

        $this->assertSame(500_000, $result['yield']);
        // 900M / 500k = 1800 ISK/SP.
        $this->assertEqualsWithDelta(1800.0, $result['iskPerSp'], 0.1);
        // 500k SP / (2700*24) = ~7.7 days.
        $this->assertEqualsWithDelta(500_000 / (2700 * 24), $result['daysSaved'], 0.01);
        // Farm: 800M*(1-0.0337) - 400M = ~373M.
        $this->assertEqualsWithDelta(800_000_000 * (1 - 0.0337) - 400_000_000, $result['farmProfitPerCycle'], 1.0);
    }

    public function test_prefers_small_injectors_when_cheaper_per_sp(): void
    {
        $character = Character::factory()->create(['total_sp' => 3_500_000]);
        // Large 900M/500k = 1800/SP; small 160M/100k = 1600/SP -> smalls win.
        $this->fakePrices(largeSell: 900_000_000, largeBuy: 800_000_000, extractor: 400_000_000, smallSell: 160_000_000);

        $result = $this->app->make(SkillEconomyService::class)->analyze($character, 0.0337, 2700);

        $this->assertTrue($result['useSmall']);
        $this->assertEqualsWithDelta(1600.0, $result['iskPerSp'], 0.1);
    }

    public function test_returns_null_without_prices(): void
    {
        Http::fake(['market.fuzzwork.co.uk/*' => Http::response([])]);
        $character = Character::factory()->create(['total_sp' => 3_500_000]);

        $this->assertNull($this->app->make(SkillEconomyService::class)->analyze($character, 0.0337, 2700));
    }
}
