<?php

namespace App\Services\Market;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Realized trading profit from actual market fills: sells inside the window
 * matched FIFO against recorded buys of the same type (buys may predate the
 * window). Sales with no recorded purchase (loot, LP-store output) are
 * reported separately as revenue — their "cost" is unknowable from market
 * data. Taxes/fees come from the wallet journal as a lump.
 */
class TradeProfitService
{
    /**
     * @return object{items: Collection<int, object>, realizedProfit: float,
     *   lootRevenue: float, feesPaid: float, days: int}
     */
    public function realized(Character $character, int $days = 30): object
    {
        $since = now()->subDays($days);

        $sells = DB::table('character_transactions')
            ->where('character_id', $character->character_id)
            ->where('is_buy', false)
            ->where('date', '>=', $since)
            ->orderBy('date')
            ->get(['type_id', 'quantity', 'unit_price', 'date']);

        if ($sells->isEmpty()) {
            return (object) [
                'items' => collect(), 'realizedProfit' => 0.0,
                'lootRevenue' => 0.0, 'feesPaid' => $this->fees($character, $since), 'days' => $days,
            ];
        }

        // FIFO queues of all recorded buys per sold type (full history — the
        // stock you are selling now may have been bought before the window).
        $buyQueues = DB::table('character_transactions')
            ->where('character_id', $character->character_id)
            ->where('is_buy', true)
            ->whereIn('type_id', $sells->pluck('type_id')->unique())
            ->orderBy('date')
            ->get(['type_id', 'quantity', 'unit_price'])
            ->groupBy('type_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => (object) [
                'remaining' => (int) $r->quantity,
                'unitPrice' => (float) $r->unit_price,
            ])->values()->all());

        $perType = [];
        $lootRevenue = 0.0;

        foreach ($sells as $sell) {
            $typeId = (int) $sell->type_id;
            $queue = $buyQueues[$typeId] ?? [];
            $quantity = (int) $sell->quantity;
            $revenue = $quantity * (float) $sell->unit_price;

            $matched = 0;
            $cost = 0.0;

            foreach ($queue as $lot) {
                if ($matched >= $quantity || $lot->remaining <= 0) {
                    continue;
                }
                $take = min($lot->remaining, $quantity - $matched);
                $lot->remaining -= $take;
                $matched += $take;
                $cost += $take * $lot->unitPrice;
            }

            if ($matched === 0) {
                $lootRevenue += $revenue;

                continue;
            }

            // Partially matched: the unmatched share counts as loot revenue.
            $matchedRevenue = $revenue * $matched / $quantity;
            $lootRevenue += $revenue - $matchedRevenue;

            $entry = $perType[$typeId] ??= (object) [
                'typeId' => $typeId, 'sold' => 0, 'revenue' => 0.0, 'cost' => 0.0,
            ];
            $entry->sold += $matched;
            $entry->revenue += $matchedRevenue;
            $entry->cost += $cost;
        }

        $names = DB::table('item_types')
            ->whereIn('type_id', array_keys($perType))
            ->pluck('name', 'type_id');

        $items = collect($perType)->map(function ($entry) use ($names) {
            $entry->name = $names[$entry->typeId] ?? ('Type #'.$entry->typeId);
            $entry->profit = $entry->revenue - $entry->cost;
            $entry->margin = $entry->cost > 0 ? $entry->profit / $entry->cost : 0.0;

            return $entry;
        })->sortByDesc('profit')->values();

        return (object) [
            'items' => $items,
            'realizedProfit' => (float) $items->sum('profit'),
            'lootRevenue' => $lootRevenue,
            'feesPaid' => $this->fees($character, $since),
            'days' => $days,
        ];
    }

    private function fees(Character $character, $since): float
    {
        return -1 * (float) DB::table('wallet_journal')
            ->where('character_id', $character->character_id)
            ->where('date', '>=', $since)
            ->whereIn('ref_type', ['transaction_tax', 'brokers_fee', 'contract_brokers_fee'])
            ->sum('amount');
    }
}
