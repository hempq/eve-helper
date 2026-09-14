<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Killmails\LossHistoryService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LossesCard extends Component
{
    public Character $character;

    public function render(LossHistoryService $losses): View
    {
        return view('livewire.losses-card', [
            'summary' => $losses->summary($this->character),
        ]);
    }
}
