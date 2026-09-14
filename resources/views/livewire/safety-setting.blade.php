<button wire:click="toggle"
        title="{{ $character->avoidsLowsec()
            ? 'High-sec only: no route, trip or scan passes through low/null-sec. Click to allow.'
            : 'Low/null-sec allowed in routing (safer routes still preferred). Click for high-sec only.' }}"
        style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; border-radius: 999px; padding: 4px 12px;
               font: 600 12px var(--font-display); letter-spacing: .04em;
               border: 1px solid {{ $character->avoidsLowsec() ? 'var(--ok)' : 'var(--gold)' }};
               background: none;
               color: {{ $character->avoidsLowsec() ? 'var(--ok)' : 'var(--gold)' }};">
    {{ $character->avoidsLowsec() ? '🛡 HIGH-SEC ONLY' : '⚠ LOW-SEC OK' }}
</button>
