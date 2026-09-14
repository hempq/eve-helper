<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\EsiResponse;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\LpStoreService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LpStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_offers_by_isk_per_lp(): void
    {
        $character = Character::factory()->create();

        DB::table('item_types')->insert([
            ['type_id' => 1000128, 'group_id' => 1, 'name' => 'Federation Navy', 'published' => true], // corp name
            ['type_id' => 500, 'group_id' => 1, 'name' => 'Navy Item A', 'published' => true],
            ['type_id' => 501, 'group_id' => 1, 'name' => 'Navy Item B', 'published' => true],
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'published' => true],
        ]);

        $expires = CarbonImmutable::now()->addHour();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/loyalty/points", [], $character)
            ->andReturn(new EsiResponse([['corporation_id' => 1000128, 'loyalty_points' => 100000]], $expires));
        $esi->shouldReceive('get')
            ->with('/loyalty/stores/1000128/offers')
            ->andReturn(new EsiResponse([
                // A: 1000 LP + 1M ISK -> item worth 5M, no required -> profit 4M -> 4000/LP.
                ['offer_id' => 1, 'type_id' => 500, 'quantity' => 1, 'lp_cost' => 1000, 'isk_cost' => 1_000_000, 'required_items' => []],
                // B: 1000 LP + 0 ISK -> item worth 2M, needs 100k Tritanium (@5 = 500k) -> profit 1.5M -> 1500/LP.
                ['offer_id' => 2, 'type_id' => 501, 'quantity' => 1, 'lp_cost' => 1000, 'isk_cost' => 0,
                    'required_items' => [['type_id' => 34, 'quantity' => 100000]]],
                // C: unaffordable (200k LP).
                ['offer_id' => 3, 'type_id' => 500, 'quantity' => 1, 'lp_cost' => 200000, 'isk_cost' => 0, 'required_items' => []],
            ], $expires));

        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '500' => ['buy' => ['percentile' => 4_000_000], 'sell' => ['percentile' => 5_000_000]],
                '501' => ['buy' => ['percentile' => 0], 'sell' => ['percentile' => 2_000_000]],
                '34' => ['buy' => ['percentile' => 0], 'sell' => ['percentile' => 5]],
            ]),
        ]);

        $result = $this->app->make(LpStoreService::class)->bestOffers($character);

        $this->assertFalse($result['needsScope']);
        $offers = $result['offers'];

        // No skills: sales tax 7.5%, broker fee 3%.
        // A: sell net 5M*0.895 = 4.475M − 1M cost → 3.475M → 3475/LP;
        //    instant net 4M*0.925 = 3.7M − 1M → 2.7M → 2700/LP.
        $a = $offers->first();
        $this->assertSame('Navy Item A', $a->item);
        $this->assertEqualsWithDelta(3475.0, $a->iskPerLp, 0.1);
        $this->assertEqualsWithDelta(2700.0, $a->iskPerLpInstant, 0.1);
        $this->assertEqualsWithDelta(5_000_000.0, $a->sellPrice, 0.1);
        $this->assertEqualsWithDelta(4_000_000.0, $a->buyPrice, 0.1);
        $this->assertTrue($a->affordable);

        // B: sell net 2M*0.895 = 1.79M − 500k required items → 1.29M → 1290/LP.
        $b = $offers->firstWhere('item', 'Navy Item B');
        $this->assertEqualsWithDelta(1290.0, $b->iskPerLp, 0.1);
    }

    public function test_missing_scope_flags_needs_scope(): void
    {
        $character = Character::factory()->create();
        $esi = $this->mock(EsiClientInterface::class);
        $esi->shouldReceive('get')
            ->with("/characters/{$character->character_id}/loyalty/points", [], $character)
            ->andThrow(new EsiRequestFailed('403 forbidden'));

        $result = $this->app->make(LpStoreService::class)->bestOffers($character);

        $this->assertTrue($result['needsScope']);
        $this->assertTrue($result['offers']->isEmpty());
    }
}
