<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Models\Character;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AlertBell extends Component
{
    public Character $character;

    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function markRead(int $alertId): void
    {
        Alert::where('character_id', $this->character->character_id)
            ->whereKey($alertId)
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        Alert::where('character_id', $this->character->character_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $alerts = Alert::where('character_id', $this->character->character_id)
            ->orderByRaw('read_at IS NOT NULL')
            ->orderByRaw("CASE severity WHEN 'urgent' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->latest()
            ->limit(20)
            ->get();

        return view('livewire.alert-bell', [
            'alerts' => $alerts,
            'unread' => $alerts->whereNull('read_at')->count(),
        ]);
    }
}
