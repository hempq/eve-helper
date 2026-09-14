<?php

namespace App\Services\Skills;

use App\Models\Character;
use App\Services\Market\PriceProviderInterface;

/**
 * Skill-injector / extractor economics with live Jita prices.
 * Sources: EVE University "Skill trading" & "Skill Farming".
 */
class SkillEconomyService
{
    // Type ids.
    private const LARGE_INJECTOR = 40520;

    private const SMALL_INJECTOR = 45635;

    private const EXTRACTOR = 40519;

    private const JITA = 60003760;

    /** An extractor always removes exactly 500k SP. */
    private const EXTRACTOR_SP = 500_000;

    public function __construct(private readonly PriceProviderInterface $prices) {}

    /**
     * SP a Large Skill Injector adds at the character's current total SP
     * (diminishing returns by bracket).
     */
    public function largeInjectorYield(int $totalSp): int
    {
        return match (true) {
            $totalSp < 5_000_000 => 500_000,
            $totalSp < 50_000_000 => 400_000,
            $totalSp < 80_000_000 => 300_000,
            default => 150_000,
        };
    }

    /**
     * @return array{
     *   yield:int, injectorPrice:float, extractorPrice:float, smallInjectorPrice:float,
     *   iskPerSp:float, daysSaved:float, spPerDay:float,
     *   farmProfitPerCycle:float, useSmall:bool
     * }|null  null when prices are unavailable
     */
    public function analyze(Character $character, float $salesTaxRate, float $spPerHour): ?array
    {
        $prices = $this->prices->prices(self::JITA, [self::LARGE_INJECTOR, self::SMALL_INJECTOR, self::EXTRACTOR]);

        $largeBuy = $prices[self::LARGE_INJECTOR]['sell'] ?? 0.0;   // what you pay to buy
        $largeSell = $prices[self::LARGE_INJECTOR]['buy'] ?? 0.0;   // what you get selling to buy orders
        $smallBuy = $prices[self::SMALL_INJECTOR]['sell'] ?? 0.0;
        $extractorBuy = $prices[self::EXTRACTOR]['sell'] ?? 0.0;

        if ($largeBuy <= 0 || $extractorBuy <= 0) {
            return null;
        }

        $totalSp = (int) ($character->total_sp ?? 0);
        $yield = $this->largeInjectorYield($totalSp);

        // A small injector is 1/5 of a large's yield; five smalls repackage to
        // one large. Under ~5.5M SP smalls can be more granular, but on
        // ISK/SP the choice is the cheaper per-SP option.
        $largePerSp = $largeBuy / $yield;
        $smallPerSp = $smallBuy > 0 ? $smallBuy / ($yield / 5) : INF;
        $useSmall = $smallPerSp < $largePerSp;
        $iskPerSp = min($largePerSp, $smallPerSp);

        $spPerDay = $spPerHour * 24;
        $daysSaved = $spPerDay > 0 ? $yield / $spPerDay : 0.0;

        // Skill farming: extract 500k SP, sell as a Large Injector to buy
        // orders, minus the extractor cost and sales tax.
        $farmProfit = $largeSell * (1 - $salesTaxRate) - $extractorBuy;

        return [
            'yield' => $yield,
            'injectorPrice' => $largeBuy,
            'injectorSell' => $largeSell,
            'extractorPrice' => $extractorBuy,
            'smallInjectorPrice' => $smallBuy,
            'iskPerSp' => $iskPerSp,
            'daysSaved' => $daysSaved,
            'spPerDay' => $spPerDay,
            'farmProfitPerCycle' => $farmProfit,
            'useSmall' => $useSmall,
        ];
    }
}
