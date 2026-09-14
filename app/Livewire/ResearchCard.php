<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Agents\ResearchAgentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

class ResearchCard extends Component
{
    public Character $character;

    public function render(ResearchAgentService $research): View
    {
        try {
            $summary = $research->summary($this->character);
        } catch (RequestException) {
            $summary = (object) ['needsScope' => false, 'agents' => collect(), 'totalValue' => 0.0];
        }

        return view('livewire.research-card', ['summary' => $summary]);
    }
}
