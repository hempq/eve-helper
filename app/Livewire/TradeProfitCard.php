<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\TradeProfitService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TradeProfitCard extends Component
{
    public Character $character;

    public int $days = 30;

    public function render(TradeProfitService $profit): View
    {
        $this->days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;

        return view('livewire.trade-profit-card', [
            'result' => $profit->realized($this->character, $this->days),
        ]);
    }
}
