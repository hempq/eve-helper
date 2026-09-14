<div class="card">
    <h2>Net worth</h2>

    @if ($worth === null)
        <p class="muted" style="margin:0">Price source unavailable — try again in a few minutes.</p>
    @else
        <div style="font: 700 26px var(--font-display); margin-bottom: 8px">
            {{ number_format($worth->total / 1_000_000, 1) }}<span class="muted" style="font-size:16px">M ISK</span>
        </div>

        @if ($history->count() >= 2)
            @php
                $values = $history->pluck('total')->map(fn ($v) => (float) $v);
                $min = min($values->min(), $values->max() * 0.98);
                $span = max(1.0, $values->max() - $min);
                $points = $values->values()->map(fn ($v, $i) => round($i * 260 / max(1, $values->count() - 1), 1).','.round(36 - ($v - $min) / $span * 32, 1))->implode(' ');
            @endphp
            <svg viewBox="0 0 260 40" style="width:100%; height:40px; margin-bottom:8px" preserveAspectRatio="none">
                <polyline points="{{ $points }}" fill="none" stroke="var(--accent)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
            </svg>
        @else
            <p class="muted" style="font-size:12.5px">The trend line appears after a few daily snapshots.</p>
        @endif

        <table>
            <tr><td class="muted">Wallet</td><td class="r num">{{ number_format($worth->wallet) }}</td></tr>
            <tr><td class="muted" title="Everything in hangars/cargo at the Jita buy (5%) price — what it would fetch if dumped today">Assets (Jita buy / contract ask)</td><td class="r num">{{ number_format($worth->assetsValue) }}</td></tr>
            <tr><td class="muted">Goods in sell orders</td><td class="r num">{{ number_format($worth->sellOrdersValue) }}</td></tr>
            <tr><td class="muted">Buy-order escrow</td><td class="r num">{{ number_format($worth->buyEscrow) }}</td></tr>
            <tr><td class="muted">Implants (active clone)</td><td class="r num">{{ number_format($worth->implantsValue) }}</td></tr>
        </table>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">Valued conservatively at Jita buy, net of your sales tax — snapshotted daily.</p>
    @endif
</div>
