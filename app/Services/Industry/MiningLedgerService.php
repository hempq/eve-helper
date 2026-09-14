<?php

namespace App\Services\Industry;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\PriceProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The 30-day mining ledger ESI keeps automatically, priced at Jita buy.
 * Needs esi-industry.read_character_mining.v1 (re-login to grant).
 */
class MiningLedgerService
{
    private const JITA = 60003760;

    public function __construct(
        private readonly EsiClientInterface $esi,
        private readonly PriceProviderInterface $prices,
    ) {}

    /**
     * @return object{needsScope: bool, ores: Collection<int, object>, totalValue: float, days: int}
     */
    public function summary(Character $character, int $days = 30): object
    {
        try {
            $rows = $this->esi->getAllPages("/characters/{$character->character_id}/mining", [], $character);
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return (object) ['needsScope' => true, 'ores' => collect(), 'totalValue' => 0.0, 'days' => $days];
        }

        $cutoff = now()->subDays($days)->toDateString();
        $rows = array_filter($rows, fn (array $r) => ($r['date'] ?? '') >= $cutoff);

        if ($rows === []) {
            return (object) ['needsScope' => false, 'ores' => collect(), 'totalValue' => 0.0, 'days' => $days];
        }

        $byType = [];
        foreach ($rows as $row) {
            $typeId = (int) $row['type_id'];
            $byType[$typeId] = ($byType[$typeId] ?? 0) + (int) $row['quantity'];
        }

        $names = DB::table('item_types')->whereIn('type_id', array_keys($byType))->pluck('name', 'type_id');
        $priceMap = $this->prices->prices(self::JITA, array_keys($byType));

        $ores = collect($byType)->map(fn (int $quantity, int $typeId) => (object) [
            'name' => $names[$typeId] ?? ('Type #'.$typeId),
            'quantity' => $quantity,
            'value' => $quantity * ($priceMap[$typeId]['buy'] ?? 0.0),
        ])->sortByDesc('value')->values();

        return (object) [
            'needsScope' => false,
            'ores' => $ores,
            'totalValue' => (float) $ores->sum('value'),
            'days' => $days,
        ];
    }
}
