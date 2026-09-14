<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Characters\NetWorthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

class WealthCard extends Component
{
    public Character $character;

    public function render(NetWorthService $worth): View
    {
        try {
            // record() also upserts today's snapshot, so the trend line
            // starts building from the first page view.
            $current = $worth->record($this->character);
        } catch (RequestException) {
            $current = null; // price source down
        }

        return view('livewire.wealth-card', [
            'worth' => $current,
            'history' => $worth->history($this->character),
        ]);
    }
}
