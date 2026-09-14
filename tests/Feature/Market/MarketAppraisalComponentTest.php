<?php

namespace Tests\Feature\Market;

use App\Livewire\MarketAppraisal;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MarketAppraisalComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_appraisal_flow_in_component(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            'type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'volume' => 0.01, 'published' => true,
        ]);

        Http::fake([
            'market.fuzzwork.co.uk/*' => Http::response([
                '34' => ['buy' => ['percentile' => 4.0], 'sell' => ['percentile' => 5.0]],
            ]),
        ]);

        Livewire::test(MarketAppraisal::class, ['character' => $character])
            ->set('paste', 'Tritanium x 1000')
            ->call('appraise')
            ->assertSee('Tritanium')
            ->assertSee('Instant sell (net)')
            ->assertSee('Sell orders (net)')
            ->assertSee('Cargo volume');
    }

    public function test_market_page_requires_login(): void
    {
        $this->get('/market')->assertRedirect(route('home'));
    }

    public function test_market_page_renders_for_character(): void
    {
        $character = Character::factory()->create(['last_synced_at' => now()]);

        $this->withSession(['character_id' => $character->character_id])
            ->get('/market')
            ->assertOk()
            ->assertSee('Market advisor')
            ->assertSee('Appraise your loot');
    }
}
