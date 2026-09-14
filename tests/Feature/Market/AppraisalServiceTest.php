<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\AppraisalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppraisalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'volume' => 0.01, 'published' => true],
            ['type_id' => 25595, 'group_id' => 966, 'name' => 'Alloyed Tritanium Bar', 'volume' => 0.01, 'published' => true],
        ]);

        // Accounting V for the character: sales tax 3.375%.
        DB::table('character_skills')->insert([
            'character_id' => $this->character->character_id,
            'skill_id' => config('eve.market.accounting_skill_id'),
            'trained_level' => 5, 'active_level' => 5, 'skillpoints' => 1,
        ]);
    }

    public function test_appraises_pasted_items_with_net_proceeds(): void
    {
        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '34' => ['buy' => ['percentile' => 4.0], 'sell' => ['percentile' => 5.0]],
                '25595' => ['buy' => ['percentile' => 40000.0], 'sell' => ['percentile' => 50000.0]],
            ]),
        ]);

        $result = $this->app->make(AppraisalService::class)->appraise(
            $this->character,
            60003760,
            "Tritanium x 1000\nAlloyed Tritanium Bar x2\nNo Such Item",
        );

        $this->assertCount(2, $result->items);
        $this->assertSame(['No Such Item'], $result->unknownNames);
        $this->assertEqualsWithDelta(0.03375, $result->salesTaxRate, 1e-9);

        // Sorted by order net desc: the bars first.
        $bars = $result->items[0];
        $this->assertSame('Alloyed Tritanium Bar', $bars->name);
        // Instant: 40000*2*(1-0.03375) = 77300; order: 50000*2*(1-0.03375-0.03) = 93625.
        $this->assertEqualsWithDelta(77300.0, $bars->instantNet, 0.01);
        $this->assertEqualsWithDelta(93625.0, $bars->orderNet, 0.01);
        $this->assertSame('sell-order', $bars->recommendation());

        $this->assertEqualsWithDelta(0.01 * 1000 + 0.01 * 2, $result->totalVolume(), 1e-6);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'station=60003760')
            && str_contains($request->url(), 'types=')
            && $request->hasHeader('User-Agent'));
    }

    public function test_prices_are_cached_between_appraisals(): void
    {
        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '34' => ['buy' => ['percentile' => 4.0], 'sell' => ['percentile' => 5.0]],
            ]),
        ]);

        $service = $this->app->make(AppraisalService::class);
        $service->appraise($this->character, 60003760, 'Tritanium');
        $service->appraise($this->character, 60003760, 'Tritanium x5');

        Http::assertSentCount(1);
    }

    public function test_item_without_market_data_flagged(): void
    {
        Http::fake(['market.fuzzwork.co.uk/*' => Http::response([])]);

        $result = $this->app->make(AppraisalService::class)
            ->appraise($this->character, 60003760, 'Tritanium');

        $this->assertSame('no-market', $result->items[0]->recommendation());
        $this->assertSame(0.0, $result->items[0]->instantNet);
    }
}
