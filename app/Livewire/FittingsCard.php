<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\FittingCostService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

class FittingsCard extends Component
{
    public Character $character;

    public function render(FittingCostService $fittings): View
    {
        try {
            $result = $fittings->replacementCosts($this->character);
        } catch (RequestException) {
            $result = (object) ['needsScope' => false, 'fittings' => collect()];
        }

        return view('livewire.fittings-card', ['result' => $result]);
    }
}
