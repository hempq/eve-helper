@php
    $mode = $character->route_security ?? 'highsec';
    $config = [
        'highsec' => ['🛡 HIGH-SEC ONLY', 'var(--ok)', 'Routes never leave high-sec. Click for High + Low.'],
        'highlow' => ['◐ HIGH + LOW', 'var(--gold)', 'Routes may use low-sec but never null-sec. Click for Anywhere.'],
        'all' => ['⚠ ANYWHERE', 'var(--bad)', 'Routes may cross null-sec (safer routes still preferred). Click for High-sec only.'],
    ][$mode] ?? ['🛡 HIGH-SEC ONLY', 'var(--ok)', ''];
@endphp
<button wire:click="cycle" title="{{ $config[2] }}"
        style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; border-radius: 999px; padding: 4px 12px;
               font: 600 12px var(--font-display); letter-spacing: .04em; background: none;
               border: 1px solid {{ $config[1] }}; color: {{ $config[1] }};">
    {{ $config[0] }}
</button>
