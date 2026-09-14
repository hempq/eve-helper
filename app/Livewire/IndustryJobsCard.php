<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Industry\IndustryService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class IndustryJobsCard extends Component
{
    public Character $character;

    public function render(IndustryService $industry): View
    {
        return view('livewire.industry-jobs-card', [
            'result' => $industry->jobs($this->character),
        ]);
    }
}
