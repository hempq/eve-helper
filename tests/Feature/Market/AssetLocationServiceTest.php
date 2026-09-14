<?php

namespace Tests\Feature\Market;

use App\Models\Character;
use App\Services\Market\AssetLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetLocationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_assets_by_root_location_through_containers(): void
    {
        $character = Character::factory()->create();

        DB::table('stations')->insert([
            'station_id' => 60003760, 'system_id' => 30000142,
            'name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
        ]);

        DB::table('item_types')->insert([
            ['type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'market_group_id' => 1857, 'published' => true],
            ['type_id' => 648, 'group_id' => 28, 'name' => 'Badger', 'market_group_id' => 82, 'published' => true],
            ['type_id' => 3467, 'group_id' => 340, 'name' => 'Small Container', 'market_group_id' => null, 'published' => true],
        ]);

        DB::table('character_assets')->insert([
            // A ship docked in Jita...
            ['character_id' => $character->character_id, 'item_id' => 1001, 'type_id' => 648,
                'quantity' => 1, 'location_id' => 60003760, 'location_flag' => 'Hangar',
                'location_type' => 'station', 'is_singleton' => true],
            // ...a container inside the ship...
            ['character_id' => $character->character_id, 'item_id' => 1002, 'type_id' => 3467,
                'quantity' => 1, 'location_id' => 1001, 'location_flag' => 'Cargo',
                'location_type' => 'item', 'is_singleton' => true],
            // ...tritanium inside the container (2 levels deep).
            ['character_id' => $character->character_id, 'item_id' => 1003, 'type_id' => 34,
                'quantity' => 5000, 'location_id' => 1002, 'location_flag' => 'Unlocked',
                'location_type' => 'item', 'is_singleton' => false],
        ]);

        $locations = $this->app->make(AssetLocationService::class)->locations($character);

        $this->assertCount(1, $locations);
        $jita = $locations->first();
        $this->assertSame(60003760, $jita->location_id);
        $this->assertSame('Jita IV - Moon 4 - Caldari Navy Assembly Plant', $jita->name);
        $this->assertSame(3, $jita->itemCount);

        // Container has no market group -> only ship and tritanium are sellable.
        $this->assertSame([648 => 1, 34 => 5000], $jita->typeQuantities);
    }

    public function test_unknown_structure_gets_placeholder_name(): void
    {
        $character = Character::factory()->create();
        DB::table('item_types')->insert([
            'type_id' => 34, 'group_id' => 18, 'name' => 'Tritanium', 'market_group_id' => 1857, 'published' => true,
        ]);
        DB::table('character_assets')->insert([
            'character_id' => $character->character_id, 'item_id' => 2001, 'type_id' => 34,
            'quantity' => 10, 'location_id' => 1035466617946, 'location_flag' => 'Hangar',
            'location_type' => 'item', 'is_singleton' => false,
        ]);

        $locations = $this->app->make(AssetLocationService::class)->locations($character);

        $this->assertStringContainsString('#1035466617946', $locations->first()->name);
    }
}
