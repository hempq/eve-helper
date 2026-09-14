<?php

namespace App\Console\Commands;

use App\Services\Market\ContractPriceService;
use Illuminate\Console\Command;
use Throwable;

class ImportContractPricesCommand extends Command
{
    protected $signature = 'eve:import-contract-prices';

    protected $description = 'Rebuild contract asking prices from the EVE Ref public-contracts snapshot';

    public function handle(ContractPriceService $service): int
    {
        try {
            $result = $service->import();
        } catch (Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Aggregated {$result['contracts']} contracts into {$result['types']} type prices.");

        return self::SUCCESS;
    }
}
