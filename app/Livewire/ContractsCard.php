<?php

namespace App\Livewire;

use App\Models\Character;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ContractsCard extends Component
{
    public Character $character;

    public function render(): View
    {
        $contracts = DB::table('character_contracts')
            ->where('character_id', $this->character->character_id)
            ->whereIn('status', ['outstanding', 'in_progress'])
            ->orderBy('date_expired')
            ->get();

        return view('livewire.contracts-card', [
            'contracts' => $contracts,
            'outstandingValue' => (float) $contracts->where('type', 'item_exchange')->sum('price'),
        ]);
    }
}
