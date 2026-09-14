<?php

namespace App\Services\Market;

use App\Models\Character;
use App\Services\Universe\RouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hauling-trade finder over the scanned hub order books: buy from sell
 * orders at hub A, haul, dump into buy orders at hub B. Quantities are
 * capped by order-book depth, cargo space and budget; results ranked by
 * total profit with ISK-per-jump shown.
 */
class TradeFinderService
{
    public function __construct(
        private readonly TradeFeeService $fees,
        private readonly RouteService $routes,
    ) {}

    /**
     * @return array{trades: Collection<int, object>, scannedAt: ?string}
     */
    public function find(
        Character $character,
        float $cargoM3 = 5000,
        float $budget = 500_000_000,
        ?int $fromStationId = null,
        ?int $toStationId = null,
        int $limit = 30,
    ): array {
        $hubs = config('eve.market.hubs');
        $salesTax = $this->fees->salesTaxRate($character);

        $scannedAt = DB::table('hub_prices')->max('scanned_at');

        if ($scannedAt === null) {
            return ['trades' => collect(), 'scannedAt' => null];
        }

        $hubSystemIds = DB::table('solar_systems')
            ->whereIn('name', array_column($hubs, 'system'))
            ->pluck('system_id', 'name');

        $trades = collect();

        foreach ($hubs as $srcStation => $src) {
            if ($fromStationId !== null && $srcStation !== $fromStationId) {
                continue;
            }

            foreach ($hubs as $dstStation => $dst) {
                if ($srcStation === $dstStation || ($toStationId !== null && $dstStation !== $toStationId)) {
                    continue;
                }

                $rows = DB::table('hub_prices as a')
                    ->join('hub_prices as b', 'b.type_id', '=', 'a.type_id')
                    ->join('item_types as t', 't.type_id', '=', 'a.type_id')
                    ->where('a.station_id', $srcStation)
                    ->where('b.station_id', $dstStation)
                    ->whereNotNull('a.best_ask')
                    ->whereNotNull('b.best_bid')
                    ->whereRaw('b.best_bid * ? > a.best_ask', [1 - $salesTax])
                    ->where('a.ask_volume', '>', 0)
                    ->where('b.bid_volume', '>', 0)
                    ->select('a.type_id', 't.name', 't.volume',
                        'a.best_ask', 'a.ask_volume', 'b.best_bid', 'b.bid_volume')
                    ->get();

                $jumps = null;
                if (isset($hubSystemIds[$src['system']], $hubSystemIds[$dst['system']])) {
                    $jumps = $this->routes->jumps(
                        (int) $hubSystemIds[$src['system']],
                        (int) $hubSystemIds[$dst['system']],
                        minSecurity: $character->minRouteSecurity(),
                    );
                }

                foreach ($rows as $row) {
                    $ask = (float) $row->best_ask;
                    $bid = (float) $row->best_bid;
                    $volume = max(0.01, (float) ($row->volume ?? 0.01));

                    $quantity = (int) floor(min(
                        (int) $row->ask_volume,
                        (int) $row->bid_volume,
                        $budget / $ask,
                        $cargoM3 / $volume,
                    ));

                    if ($quantity < 1) {
                        continue;
                    }

                    $invest = $ask * $quantity;
                    $profit = ($bid * (1 - $salesTax) - $ask) * $quantity;

                    if ($profit < 100_000) {
                        continue; // noise
                    }

                    $trades->push((object) [
                        'typeId' => (int) $row->type_id,
                        'name' => $row->name,
                        'from' => $src['system'],
                        'fromStationId' => $srcStation,
                        'to' => $dst['system'],
                        'toStationId' => $dstStation,
                        'buyPrice' => $ask,
                        'sellPrice' => $bid,
                        'quantity' => $quantity,
                        'cargo' => $quantity * $volume,
                        'invest' => $invest,
                        'profit' => $profit,
                        'margin' => $invest > 0 ? $profit / $invest : 0,
                        'jumps' => $jumps,
                        'iskPerJump' => $jumps !== null && $jumps > 0 ? $profit / $jumps : null,
                    ]);
                }
            }
        }

        return [
            'trades' => $trades->sortByDesc('profit')->take($limit)->values(),
            'scannedAt' => $scannedAt,
        ];
    }
}
