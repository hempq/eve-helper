<?php

namespace App\Services\Characters;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "What do you actually live on?" — the wallet journal grouped into
 * player-meaningful income/spending categories over a window.
 */
class WalletAnalyticsService
{
    /** journal ref_type => bucket label; unlisted types fall back to a humanized ref_type. */
    private const BUCKETS = [
        'bounty_prizes' => 'Bounties (ratting)',
        'agent_mission_reward' => 'Missions',
        'agent_mission_time_bonus_reward' => 'Missions',
        'market_transaction' => 'Market trading',
        'transaction_tax' => 'Trade taxes & fees',
        'brokers_fee' => 'Trade taxes & fees',
        'market_escrow' => 'Market escrow',
        'contract_price' => 'Contracts',
        'contract_reward' => 'Contracts',
        'contract_brokers_fee' => 'Trade taxes & fees',
        'insurance' => 'Insurance',
        'player_donation' => 'Donations',
        'corporation_account_withdrawal' => 'Corp transfers',
        'structure_gate_jump' => 'Travel fees',
        'reprocessing_tax' => 'Industry fees',
        'industry_job_tax' => 'Industry fees',
        'manufacturing' => 'Industry',
        'skill_purchase' => 'Skills & clones',
        'clone_activation' => 'Skills & clones',
        'jump_clone_activation_fee' => 'Skills & clones',
        'jump_clone_installation_fee' => 'Skills & clones',
        'planetary_import_tax' => 'PI taxes',
        'planetary_export_tax' => 'PI taxes',
        'ess_escrow_transfer' => 'Bounties (ratting)',
        'daily_goal_payouts' => 'Login rewards',
    ];

    /** Coarse buckets for the daily earnings chart. */
    private const CHART_BUCKETS = [
        'Bounties (ratting)' => 'Bounties',
        'Missions' => 'Missions',
        'Market trading' => 'Market sales',
        'Contracts' => 'Contracts',
    ];

    /**
     * Daily income series for the earnings chart (income side only — what
     * actually landed in the wallet each day).
     *
     * @return object{days: Collection<int, object{date: string, buckets: array<string, float>, total: float}>,
     *   buckets: list<string>, total: float}
     */
    public function dailyIncome(Character $character, int $days = 14): object
    {
        $rows = DB::table('wallet_journal')
            ->where('character_id', $character->character_id)
            ->where('date', '>=', now()->subDays($days)->startOfDay())
            ->where('amount', '>', 0)
            ->get(['ref_type', 'amount', 'date']);

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[now()->subDays($i)->toDateString()] = [];
        }

        foreach ($rows as $row) {
            $day = substr((string) $row->date, 0, 10);
            if (! array_key_exists($day, $series)) {
                continue;
            }
            $fine = self::BUCKETS[$row->ref_type] ?? 'Other';
            $bucket = self::CHART_BUCKETS[$fine] ?? 'Other';
            $series[$day][$bucket] = ($series[$day][$bucket] ?? 0.0) + (float) $row->amount;
        }

        $daysOut = collect($series)->map(fn (array $buckets, string $date) => (object) [
            'date' => $date,
            'buckets' => $buckets,
            'total' => (float) array_sum($buckets),
        ])->values();

        return (object) [
            'days' => $daysOut,
            'buckets' => [...array_values(array_unique(array_values(self::CHART_BUCKETS))), 'Other'],
            'total' => (float) $daysOut->sum('total'),
        ];
    }

    /**
     * @return object{income: Collection<int, object{bucket: string, amount: float}>,
     *   spending: Collection<int, object{bucket: string, amount: float}>,
     *   totalIncome: float, totalSpending: float, net: float, days: int}
     */
    public function breakdown(Character $character, int $days = 30): object
    {
        $rows = DB::table('wallet_journal')
            ->where('character_id', $character->character_id)
            ->where('date', '>=', now()->subDays($days))
            ->get(['ref_type', 'amount']);

        $income = [];
        $spending = [];

        foreach ($rows as $row) {
            $amount = (float) $row->amount;

            if ($amount == 0.0) {
                continue;
            }

            $bucket = self::BUCKETS[$row->ref_type] ?? ucfirst(str_replace('_', ' ', (string) $row->ref_type));

            if ($amount > 0) {
                $income[$bucket] = ($income[$bucket] ?? 0.0) + $amount;
            } else {
                $spending[$bucket] = ($spending[$bucket] ?? 0.0) - $amount;
            }
        }

        $toSorted = fn (array $byBucket) => collect($byBucket)
            ->map(fn (float $amount, string $bucket) => (object) ['bucket' => $bucket, 'amount' => $amount])
            ->sortByDesc('amount')
            ->values();

        return (object) [
            'income' => $toSorted($income),
            'spending' => $toSorted($spending),
            'totalIncome' => (float) array_sum($income),
            'totalSpending' => (float) array_sum($spending),
            'net' => (float) (array_sum($income) - array_sum($spending)),
            'days' => $days,
        ];
    }
}
