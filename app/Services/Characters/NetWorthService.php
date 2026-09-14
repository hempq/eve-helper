<?php

namespace App\Services\Characters;

use App\Models\Character;
use App\Services\Market\PriceProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Character net worth: liquid ISK plus everything ESI already syncs, valued
 * conservatively at the Jita buy percentile (what it would fetch if dumped
 * today). Snapshotted daily so the dashboard can chart the trend.
 */
class NetWorthService
{
    private const JITA = 60003760;

    public function __construct(private readonly PriceProviderInterface $prices) {}

    /**
     * @return object{wallet: float, assetsValue: float, sellOrdersValue: float,
     *   buyEscrow: float, implantsValue: float, total: float}
     */
    public function compute(Character $character): object
    {
        $wallet = (float) ($character->wallet_balance ?? 0);

        // Assets, aggregated per type before pricing.
        $assetQty = DB::table('character_assets')
            ->where('character_id', $character->character_id)
            ->selectRaw('type_id, SUM(quantity) as qty')
            ->groupBy('type_id')
            ->pluck('qty', 'type_id');

        $implantTypes = DB::table('character_implants')
            ->where('character_id', $character->character_id)
            ->pluck('type_id');

        $prices = $this->prices->prices(self::JITA, [
            ...$assetQty->keys()->map(fn ($id) => (int) $id),
            ...$implantTypes->map(fn ($id) => (int) $id),
        ]);

        $assetsValue = 0.0;
        foreach ($assetQty as $typeId => $qty) {
            $assetsValue += ($prices[(int) $typeId]['buy'] ?? 0.0) * (int) $qty;
        }

        $implantsValue = 0.0;
        foreach ($implantTypes as $typeId) {
            $implantsValue += $prices[(int) $typeId]['buy'] ?? 0.0;
        }

        $orders = DB::table('character_orders')
            ->where('character_id', $character->character_id)
            ->get(['is_buy_order', 'price', 'volume_remain']);

        // Sell orders hold goods (valued at ask); buy orders hold escrowed ISK.
        $sellOrdersValue = (float) $orders->where('is_buy_order', false)
            ->sum(fn ($o) => $o->price * $o->volume_remain);
        $buyEscrow = (float) $orders->where('is_buy_order', true)
            ->sum(fn ($o) => $o->price * $o->volume_remain);

        return (object) [
            'wallet' => $wallet,
            'assetsValue' => $assetsValue,
            'sellOrdersValue' => $sellOrdersValue,
            'buyEscrow' => $buyEscrow,
            'implantsValue' => $implantsValue,
            'total' => $wallet + $assetsValue + $sellOrdersValue + $buyEscrow + $implantsValue,
        ];
    }

    /** Upserts today's snapshot; returns the computed breakdown. */
    public function record(Character $character): object
    {
        $worth = $this->compute($character);

        DB::table('net_worth_snapshots')->upsert([[
            'character_id' => $character->character_id,
            'date' => now()->toDateString(),
            'wallet' => $worth->wallet,
            'assets_value' => $worth->assetsValue,
            'sell_orders_value' => $worth->sellOrdersValue,
            'buy_escrow' => $worth->buyEscrow,
            'implants_value' => $worth->implantsValue,
            'total' => $worth->total,
        ]], ['character_id', 'date']);

        return $worth;
    }

    /** @return Collection<int, object> snapshots oldest first */
    public function history(Character $character, int $days = 90): Collection
    {
        return DB::table('net_worth_snapshots')
            ->where('character_id', $character->character_id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }
}
