<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Characters\WalletAnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EarningsCard extends Component
{
    public Character $character;

    public int $days = 14;

    public function render(WalletAnalyticsService $analytics): View
    {
        $this->days = in_array($this->days, [7, 14, 30], true) ? $this->days : 14;

        return view('livewire.earnings-card', [
            'series' => $analytics->dailyIncome($this->character, $this->days),
        ]);
    }
}
