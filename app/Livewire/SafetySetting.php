<?php

namespace App\Livewire;

use App\Models\Character;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Global routing preference toggle: high-sec-only planning on/off.
 */
class SafetySetting extends Component
{
    public Character $character;

    public function toggle(): void
    {
        $this->character->forceFill([
            'avoid_lowsec' => ! $this->character->avoidsLowsec(),
        ])->save();

        // Routing-dependent components on the page must recalculate.
        $this->dispatch('safety-changed');
    }

    public function render(): View
    {
        return view('livewire.safety-setting');
    }
}
