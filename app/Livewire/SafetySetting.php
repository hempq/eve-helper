<?php

namespace App\Livewire;

use App\Models\Character;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Global routing preference: cycles High-sec only → High + Low → Anywhere.
 */
class SafetySetting extends Component
{
    public Character $character;

    private const CYCLE = ['highsec' => 'highlow', 'highlow' => 'all', 'all' => 'highsec'];

    public function cycle(): void
    {
        $current = $this->character->route_security ?? 'highsec';

        $this->character->forceFill([
            'route_security' => self::CYCLE[$current] ?? 'highsec',
        ])->save();

        // Routing-dependent components on the page must recalculate.
        $this->dispatch('safety-changed');
    }

    public function render(): View
    {
        return view('livewire.safety-setting');
    }
}
