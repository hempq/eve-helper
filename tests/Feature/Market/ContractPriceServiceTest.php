<?php

namespace Tests\Feature\Market;

use App\Services\Market\ContractPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function writeSnapshot(array $contracts, array $items, array $dynamic = []): string
    {
        $dir = sys_get_temp_dir().'/contract-import-test-'.uniqid();
        mkdir($dir);

        $csv = function (string $file, array $header, array $rows) use ($dir) {
            $handle = fopen("{$dir}/{$file}", 'w');
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        };

        $csv('contracts.csv', ['contract_id', 'price', 'type', 'title'], $contracts);
        $csv('contract_items.csv', ['contract_id', 'type_id', 'quantity', 'is_included', 'is_blueprint_copy'], $items);
        $csv('contract_dynamic_items.csv', ['contract_id', 'item_id'], $dynamic);

        return $dir;
    }

    public function test_aggregates_single_item_exchange_contracts(): void
    {
        $dir = $this->writeSnapshot(
            contracts: [
                [1, 100_000_000, 'item_exchange', 'Gist X-Type, cheap'],
                [2, 300_000_000, 'item_exchange', 'same item, pricier'],
                [3, 200_000_000, 'item_exchange', 'two of them'],
                [4, 500_000_000, 'auction', 'auctions ignored'],
                [5, 50_000_000, 'item_exchange', 'mixed-type excluded'],
                [6, 70_000_000, 'item_exchange', 'BPC excluded'],
                [7, 90_000_000, 'item_exchange', 'buyer-provides excluded'],
                [8, 60_000_000, 'item_exchange', 'abyssal excluded'],
            ],
            items: [
                [1, 19406, 1, 'true', ''],
                [2, 19406, 1, 'true', ''],
                [3, 19406, 2, 'true', ''], // 100M each
                [4, 19406, 1, 'true', ''],
                [5, 19406, 1, 'true', ''],
                [5, 34, 1000, 'true', ''],
                [6, 19406, 1, 'true', 'true'],
                [7, 19406, 1, 'false', ''],
                [8, 19406, 1, 'true', ''],
            ],
            dynamic: [[8, 1035314884469]],
        );

        $result = $this->app->make(ContractPriceService::class)->importFromDirectory($dir);

        $this->assertSame(['contracts' => 3, 'types' => 1], $result);

        $row = DB::table('contract_prices')->where('type_id', 19406)->first();
        $this->assertSame(3, (int) $row->sample_count);
        $this->assertEqualsWithDelta(100_000_000, $row->min_price, 0.1);
        // Sorted unit prices: 100M, 100M, 300M -> p20 = 100M, median = 100M.
        $this->assertEqualsWithDelta(100_000_000, $row->p20_price, 0.1);
        $this->assertEqualsWithDelta(100_000_000, $row->median_price, 0.1);
    }

    public function test_reimport_replaces_previous_prices(): void
    {
        DB::table('contract_prices')->insert([
            'type_id' => 999, 'sample_count' => 5, 'min_price' => 1,
            'p20_price' => 1, 'median_price' => 1, 'updated_at' => now(),
        ]);

        $dir = $this->writeSnapshot(
            contracts: [[1, 10_000_000, 'item_exchange', 'x']],
            items: [[1, 19406, 1, 'true', '']],
        );

        $this->app->make(ContractPriceService::class)->importFromDirectory($dir);

        $this->assertDatabaseMissing('contract_prices', ['type_id' => 999]);
        $this->assertDatabaseHas('contract_prices', ['type_id' => 19406]);
    }

    public function test_prices_lookup(): void
    {
        DB::table('contract_prices')->insert([
            'type_id' => 19406, 'sample_count' => 4, 'min_price' => 90_000_000,
            'p20_price' => 100_000_000, 'median_price' => 120_000_000, 'updated_at' => now(),
        ]);

        $prices = $this->app->make(ContractPriceService::class)->prices([19406, 34]);

        $this->assertArrayHasKey(19406, $prices);
        $this->assertArrayNotHasKey(34, $prices);
        $this->assertEqualsWithDelta(100_000_000, $prices[19406]->p20Price, 0.1);
    }
}
