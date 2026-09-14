<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\LpStoreService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

class LpStore extends Component
{
    public Character $character;

    public function render(LpStoreService $store): View
    {
        $needsScope = false;
        $offers = collect();

        try {
            $result = $store->bestOffers($this->character);
            $needsScope = $result['needsScope'];
            $offers = $result['offers'];
        } catch (RequestException) {
            // prices unavailable
        }

        return view('livewire.lp-store', [
            'offers' => $offers,
            'needsScope' => $needsScope,
        ]);
    }
}
