<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Industry\IndustryService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class BlueprintsCard extends Component
{
    public Character $character;

    public function render(IndustryService $industry): View
    {
        return view('livewire.blueprints-card', [
            'result' => $industry->blueprints($this->character),
        ]);
    }
}
