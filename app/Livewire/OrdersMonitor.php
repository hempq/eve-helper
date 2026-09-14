<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\UndercutService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Open-order undercut monitor; polls every 2 minutes (the character-orders
 * ESI cache is 20 minutes, region prices 5 — our client caches accordingly).
 */
class OrdersMonitor extends Component
{
    public Character $character;

    public function render(UndercutService $undercut): View
    {
        $rows = $undercut->check($this->character);

        return view('livewire.orders-monitor', [
            'rows' => $rows,
            'undercutCount' => $rows->where('undercut', true)->count(),
        ]);
    }
}
