<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\StationTradingService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class StationTrading extends Component
{
    public Character $character;

    public int $stationId = 0;

    public int $minMarginPct = 8;

    public function render(StationTradingService $trading): View
    {
        $hubs = config('eve.market.hubs');

        if (! isset($hubs[$this->stationId])) {
            $this->stationId = (int) config('eve.market.default_hub');
        }
        $this->minMarginPct = max(2, min(50, $this->minMarginPct));

        $result = $trading->find($this->character, $this->stationId, $this->minMarginPct / 100);

        return view('livewire.station-trading', [
            'hubs' => $hubs,
            'trades' => $result['trades'],
            'scannedAt' => $result['scannedAt'],
        ]);
    }
}
