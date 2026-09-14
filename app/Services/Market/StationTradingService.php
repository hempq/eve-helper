<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Station-trading (0-jump flipping) scanner over a hub's scanned order
 * book: place a buy order at best bid, relist at best ask — profit is the
 * spread minus broker fees on BOTH orders and sales tax, with the pilot's
 * real skill/standing rates. Ranked by daily flip potential (margin ×
 * traded volume), because a huge spread nobody trades is worthless.
 */
class StationTradingService
{
    /** Volume lookups are cached-daily ESI calls; cap them per scan. */
    private const VOLUME_LOOKUP_CAP = 60;

    /**
     * Spreads above this are scam walls or dead markets (a 1-ISK bid against
     * a 389B ask), not flips — your order on the thin side never trades.
     */
    private const MAX_MARGIN = 1.0;

    /** A bid below this is bait, not a market. */
    private const MIN_BID = 1_000.0;

    public function __construct(
        private readonly TradeFeeService $fees,
        private readonly MarketHistoryService $history,
    ) {}

    /**
     * @return array{trades: Collection<int, object>, scannedAt: ?string}
     */
    public function find(
        Character $character,
        int $stationId,
        float $minMargin = 0.08,
        float $maxInvest = 1_000_000_000,
        int $limit = 30,
    ): array {
        $regionId = (int) (config("eve.market.hubs.{$stationId}.region_id") ?? 0);
        $salesTax = $this->fees->salesTaxRate($character);
        $brokerFee = $this->fees->brokerFeeRate($character, $stationId);

        $scannedAt = DB::table('hub_prices')->where('station_id', $stationId)->max('scanned_at');

        if ($scannedAt === null) {
            return ['trades' => collect(), 'scannedAt' => null];
        }

        $rows = DB::table('hub_prices as p')
            ->join('item_types as t', 't.type_id', '=', 'p.type_id')
            ->where('p.station_id', $stationId)
            ->whereNotNull('p.best_bid')
            ->whereNotNull('p.best_ask')
            ->where('p.best_bid', '>=', self::MIN_BID)
            ->where('p.best_bid', '<=', $maxInvest)
            // Raw spread window before fees — cheap pre-cut in SQL.
            ->whereRaw('p.best_ask > p.best_bid * ?', [1 + $minMargin])
            ->whereRaw('p.best_ask < p.best_bid * ?', [1 + 2 * self::MAX_MARGIN])
            ->select('p.type_id', 't.name', 'p.best_bid', 'p.best_ask', 'p.bid_volume', 'p.ask_volume')
            ->orderByRaw('(p.best_ask - p.best_bid) * p.ask_volume DESC')
            ->limit(500)
            ->get();

        $candidates = $rows->map(function ($row) use ($salesTax, $brokerFee) {
            $bid = (float) $row->best_bid;
            $ask = (float) $row->best_ask;

            // Buy via your own buy order (broker fee on it), relist at the
            // ask (broker fee + sales tax on the sale).
            $cost = $bid * (1 + $brokerFee);
            $revenue = $ask * (1 - $salesTax - $brokerFee);
            $profit = $revenue - $cost;

            return (object) [
                'typeId' => (int) $row->type_id,
                'name' => $row->name,
                'bid' => $bid,
                'ask' => $ask,
                'profitPerUnit' => $profit,
                'margin' => $cost > 0 ? $profit / $cost : 0.0,
                'askDepth' => (int) $row->ask_volume,
                'bidDepth' => (int) $row->bid_volume,
                'dailyVolume' => null,
                'dailyPotential' => null,
            ];
        })->filter(fn ($t) => $t->margin >= $minMargin && $t->margin <= self::MAX_MARGIN);

        // Liquidity pass on the best raw candidates only.
        $ranked = $candidates
            ->sortByDesc(fn ($t) => $t->profitPerUnit * min($t->askDepth, $t->bidDepth))
            ->take(self::VOLUME_LOOKUP_CAP)
            ->each(function ($trade) use ($regionId) {
                if ($regionId > 0 && config('eve.market.history_enabled')) {
                    $trade->dailyVolume = $this->history->averageDailyVolume($regionId, $trade->typeId);
                }
                // You realistically capture a slice of the daily flow.
                $trade->dailyPotential = $trade->dailyVolume !== null
                    ? $trade->profitPerUnit * $trade->dailyVolume * 0.1
                    : null;
            })
            ->filter(fn ($t) => $t->dailyVolume === null || $t->dailyVolume >= 1)
            ->sortByDesc(fn ($t) => $t->dailyPotential ?? $t->profitPerUnit)
            ->take($limit)
            ->values();

        return ['trades' => $ranked, 'scannedAt' => $scannedAt];
    }
}
