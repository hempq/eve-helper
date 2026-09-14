<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Industry\MiningLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

class MiningCard extends Component
{
    public Character $character;

    public function render(MiningLedgerService $mining): View
    {
        try {
            $summary = $mining->summary($this->character);
        } catch (RequestException) {
            $summary = (object) ['needsScope' => false, 'ores' => collect(), 'totalValue' => 0.0, 'days' => 30];
        }

        return view('livewire.mining-card', ['summary' => $summary]);
    }
}
