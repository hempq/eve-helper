<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use RuntimeException;

/**
 * Contract asking prices for items that barely trade on the market
 * (deadspace/faction loot, BPCs). Adam4EVE exposes no public contract API,
 * so prices are computed locally from the EVE Ref public-contracts snapshot
 * (~6 MB tar.bz2, refreshed twice hourly): every outstanding single-item
 * item_exchange contract contributes an ask price; the 20th percentile is
 * the "competitive ask", the median a robust typical listing.
 */
class ContractPriceService
{
    public function __construct(
        private readonly string $userAgent,
        private readonly string $snapshotUrl,
    ) {}

    /**
     * Download the latest snapshot and rebuild the contract_prices table.
     *
     * @return array{contracts: int, types: int}
     */
    public function import(): array
    {
        $dir = storage_path('app/contract-import');
        File::ensureDirectoryExists($dir);

        $archive = "{$dir}/latest.tar.bz2";

        $response = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->timeout(120)
            ->sink($archive)
            ->get($this->snapshotUrl)
            ->throw();

        $this->extract($archive, $dir);

        return $this->importFromDirectory($dir);
    }

    /**
     * Aggregate an extracted snapshot directory (contracts.csv,
     * contract_items.csv, optionally contract_dynamic_items.csv).
     *
     * @return array{contracts: int, types: int}
     */
    public function importFromDirectory(string $dir): array
    {
        // Asking price of each eligible contract (single type, seller-included
        // only, no BPCs, no mutated/abyssal items whose price says nothing
        // about the base type).
        $prices = $this->contractPrices($dir);

        $byType = [];
        foreach ($prices as [$typeId, $unitPrice]) {
            $byType[$typeId][] = $unitPrice;
        }

        $rows = [];
        $now = now();

        foreach ($byType as $typeId => $unitPrices) {
            sort($unitPrices);

            $rows[] = [
                'type_id' => $typeId,
                'sample_count' => count($unitPrices),
                'min_price' => $unitPrices[0],
                'p20_price' => $this->percentile($unitPrices, 0.20),
                'median_price' => $this->percentile($unitPrices, 0.50),
                'updated_at' => $now,
            ];
        }

        DB::table('contract_prices')->truncate();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('contract_prices')->insert($chunk);
        }

        return ['contracts' => count($prices), 'types' => count($rows)];
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, object{sampleCount: int, minPrice: float, p20Price: float, medianPrice: float}>
     */
    public function prices(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return DB::table('contract_prices')
            ->whereIn('type_id', $typeIds)
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->type_id => (object) [
                'sampleCount' => (int) $row->sample_count,
                'minPrice' => (float) $row->min_price,
                'p20Price' => (float) $row->p20_price,
                'medianPrice' => (float) $row->median_price,
            ]])
            ->all();
    }

    /**
     * @return list<array{0: int, 1: float}> [type id, unit asking price] per
     *   eligible contract
     */
    private function contractPrices(string $dir): array
    {
        // price per outstanding item_exchange contract.
        $contractPrice = [];
        foreach ($this->csvRows("{$dir}/contracts.csv") as $row) {
            if (($row['type'] ?? '') === 'item_exchange' && (float) ($row['price'] ?? 0) > 0) {
                $contractPrice[(int) $row['contract_id']] = (float) $row['price'];
            }
        }

        // Contracts containing mutated (abyssal) items are excluded entirely.
        $dynamic = [];
        if (is_file("{$dir}/contract_dynamic_items.csv")) {
            foreach ($this->csvRows("{$dir}/contract_dynamic_items.csv") as $row) {
                if (isset($row['contract_id'])) {
                    $dynamic[(int) $row['contract_id']] = true;
                }
            }
        }

        // Fold items per contract: track the single type id (or mark mixed),
        // total quantity, and any disqualifier.
        $typeOf = [];
        $quantityOf = [];
        $excluded = $dynamic;

        foreach ($this->csvRows("{$dir}/contract_items.csv") as $row) {
            $contractId = (int) ($row['contract_id'] ?? 0);

            if (! isset($contractPrice[$contractId]) || isset($excluded[$contractId])) {
                continue;
            }

            // Buyer-provided items make it a trade, not a sale; BPC prices
            // depend on runs/ME/TE, not the type.
            if (($row['is_included'] ?? '') !== 'true' || ($row['is_blueprint_copy'] ?? '') === 'true') {
                $excluded[$contractId] = true;

                continue;
            }

            $typeId = (int) ($row['type_id'] ?? 0);

            if (isset($typeOf[$contractId]) && $typeOf[$contractId] !== $typeId) {
                $excluded[$contractId] = true; // mixed-type contract

                continue;
            }

            $typeOf[$contractId] = $typeId;
            $quantityOf[$contractId] = ($quantityOf[$contractId] ?? 0) + max(1, (int) ($row['quantity'] ?? 1));
        }

        $prices = [];
        foreach ($typeOf as $contractId => $typeId) {
            if (! isset($excluded[$contractId])) {
                $prices[] = [$typeId, $contractPrice[$contractId] / $quantityOf[$contractId]];
            }
        }

        return $prices;
    }

    /**
     * @return iterable<array<string, string>> header-keyed rows
     */
    private function csvRows(string $path): iterable
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                return;
            }

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($header)) {
                    yield array_combine($header, $row);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param  list<float>  $sorted */
    private function percentile(array $sorted, float $p): float
    {
        return $sorted[min(count($sorted) - 1, (int) floor($p * count($sorted)))];
    }

    private function extract(string $archive, string $dir): void
    {
        $tar = preg_replace('/\.bz2$/', '', $archive);

        $in = bzopen($archive, 'r');
        $out = fopen($tar, 'w');

        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot decompress {$archive}");
        }

        while (! feof($in)) {
            fwrite($out, bzread($in, 1 << 20));
        }
        bzclose($in);
        fclose($out);

        // Clear stale CSVs so a shrunken snapshot never mixes with old files.
        foreach (glob("{$dir}/*.csv") ?: [] as $old) {
            unlink($old);
        }

        (new PharData($tar))->extractTo($dir, overwrite: true);
        unlink($tar);
    }
}
