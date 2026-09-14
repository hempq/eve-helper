<?php

namespace App\Console\Commands;

use App\Services\Market\HubPriceScanService;
use Illuminate\Console\Command;

class ScanHubsCommand extends Command
{
    protected $signature = 'eve:scan-hubs';

    protected $description = 'Scan the full order books of the five trade hubs into hub_prices (for the trade finder)';

    public function handle(HubPriceScanService $scanner): int
    {
        foreach (config('eve.market.hubs') as $stationId => $hub) {
            $this->info("Scanning {$hub['system']} (region {$hub['region_id']}) ...");

            $types = $scanner->scan($hub['region_id'], $stationId, function (int $page, int $pages) {
                if ($page % 25 === 0 || $page === $pages) {
                    $this->line("  page {$page}/{$pages}");
                }
            });

            $this->info("  {$types} types priced at {$hub['system']}.");
        }

        return self::SUCCESS;
    }
}
