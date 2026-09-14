<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Characters\WalletAnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class IncomeCard extends Component
{
    public Character $character;

    public int $days = 30;

    public function render(WalletAnalyticsService $analytics): View
    {
        $this->days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;

        return view('livewire.income-card', [
            'breakdown' => $analytics->breakdown($this->character, $this->days),
        ]);
    }
}
