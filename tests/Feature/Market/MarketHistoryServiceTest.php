<?php

namespace Tests\Feature\Market;

use App\Services\Market\AppraisalItem;
use App\Services\Market\MarketHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_average_daily_volume_over_recent_window(): void
    {
        Http::fake([
            'esi.evetech.net/markets/10000002/history*' => Http::response([
                ['date' => now()->subDays(2)->toDateString(), 'volume' => 100],
                ['date' => now()->subDay()->toDateString(), 'volume' => 300],
                ['date' => now()->subDays(90)->toDateString(), 'volume' => 99999], // outside window
            ], 200, ['Expires' => now()->addDay()->toRfc7231String()]),
        ]);

        $avg = $this->app->make(MarketHistoryService::class)->averageDailyVolume(10000002, 34);

        $this->assertEqualsWithDelta(200.0, $avg, 0.01); // (100+300)/2, old row excluded
    }

    public function test_no_history_returns_zero(): void
    {
        Http::fake(['esi.evetech.net/markets/*' => Http::response([], 200, ['Expires' => now()->addDay()->toRfc7231String()])]);

        $this->assertSame(0.0, $this->app->make(MarketHistoryService::class)->averageDailyVolume(10000002, 999));
    }

    public function test_item_recommendation_and_fill_time(): void
    {
        // Liquid item: sell order vs instant by net.
        $liquid = new AppraisalItem(34, 'Tritanium', 10000, 0.01, 4.0, 5.0, 38000, 46000, avgDailyVolume: 5_000_000);
        $this->assertSame('sell-order', $liquid->recommendation());
        $this->assertEqualsWithDelta(10000 / 5_000_000, $liquid->daysToSell(), 1e-9);

        // Illiquid deadspace loot: barely trades -> contract.
        $deadspace = new AppraisalItem(1, 'Gistii B-Type Small Shield Booster', 1, 5.0, 74_000_000, 94_000_000, 71_000_000, 89_000_000, avgDailyVolume: 0.3);
        $this->assertSame('contract', $deadspace->recommendation());
        $this->assertEqualsWithDelta(1 / 0.3, $deadspace->daysToSell(), 1e-6);

        // No volume data: falls back to net comparison, no fill-time.
        $unknown = new AppraisalItem(2, 'Thing', 5, 1.0, 10, 12, 45, 50, avgDailyVolume: null);
        $this->assertNull($unknown->daysToSell());
        $this->assertContains($unknown->recommendation(), ['sell-order', 'instant']);
    }
}
