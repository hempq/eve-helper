<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Industry\PlanetaryService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PlanetaryCard extends Component
{
    public Character $character;

    public function render(PlanetaryService $planetary): View
    {
        return view('livewire.planetary-card', [
            'result' => $planetary->colonies($this->character),
        ]);
    }
}
