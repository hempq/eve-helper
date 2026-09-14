<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Characters\WalletAnalyticsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Account-wide totals across every linked character: wealth, wallet and
 * 30-day net income per character plus the grand total.
 */
class AccountOverviewCard extends Component
{
    public function render(WalletAnalyticsService $analytics): View
    {
        $latestWorth = DB::table('net_worth_snapshots as n')
            ->whereRaw('n.date = (select max(date) from net_worth_snapshots where character_id = n.character_id)')
            ->pluck('total', 'character_id');

        $rows = Character::orderBy('name')->get()->map(fn (Character $character) => (object) [
            'name' => $character->name,
            'wallet' => (float) ($character->wallet_balance ?? 0),
            'worth' => isset($latestWorth[$character->character_id]) ? (float) $latestWorth[$character->character_id] : null,
            'net30' => $analytics->breakdown($character, 30)->net,
            'lastSync' => $character->last_synced_at,
        ]);

        return view('livewire.account-overview-card', [
            'rows' => $rows,
            'totalWorth' => (float) $rows->sum(fn ($r) => $r->worth ?? $r->wallet),
            'totalNet30' => (float) $rows->sum('net30'),
        ]);
    }
}
