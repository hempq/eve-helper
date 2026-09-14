<div class="card wide" style="margin-top: 24px">
    <h2>Realized trading profit
        <span class="muted" style="text-transform:none; letter-spacing:0">— last
            <select wire:model.live="days" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 2px 6px; font: inherit; font-size: 13px;">
                <option value="7">7</option><option value="30">30</option><option value="90">90</option>
            </select> days, from actual market fills
        </span>
    </h2>

    @if ($result->items->isEmpty() && $result->lootRevenue == 0.0)
        <p class="muted" style="margin:0">No market sales recorded in this window (fills sync with the character, ~every 15 minutes).</p>
    @else
        <div style="display:flex; gap:16px; margin-bottom: 8px; flex-wrap: wrap">
            <span class="chip {{ $result->realizedProfit >= 0 ? 'up' : 'down' }}"><b>flipped: {{ number_format($result->realizedProfit / 1_000_000, 1) }}M</b></span>
            <span class="chip gold" title="Sales with no recorded purchase — loot, LP-store output, old stock. Revenue only; cost unknown.">loot/other sales: {{ number_format($result->lootRevenue / 1_000_000, 1) }}M</span>
            <span class="chip down" title="Transaction tax + broker fees paid in the window (wallet journal)">fees: {{ number_format($result->feesPaid / 1_000_000, 1) }}M</span>
            <span class="chip {{ $result->netProfit >= 0 ? 'up' : 'down' }}" title="Flipped profit + loot revenue − every tax and broker fee paid"><b>net after fees: {{ number_format($result->netProfit / 1_000_000, 1) }}M</b></span>
        </div>

        @if ($result->items->isNotEmpty())
            <div class="tablewrap">
                <table class="sortable">
                    <tr>
                        <th>Item</th><th class="r">Sold</th>
                        <th class="r" title="FIFO-matched buy cost">Cost</th>
                        <th class="r">Revenue</th><th class="r">Profit</th><th class="r">Margin</th>
                    </tr>
                    @foreach ($result->items->take(15) as $item)
                        <tr>
                            <td>{{ $item->name }}</td>
                            <td class="r num">{{ number_format($item->sold) }}</td>
                            <td class="r num muted">{{ number_format($item->cost) }}</td>
                            <td class="r num">{{ number_format($item->revenue) }}</td>
                            <td class="r num {{ $item->profit >= 0 ? 'ok' : 'bad' }}">{{ number_format($item->profit) }}</td>
                            <td class="r num">{{ number_format($item->margin * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
        <p class="muted" style="font-size:12.5px; margin-bottom:0">
            Sells in the window matched FIFO against your recorded buys (any age). Fees are not per-item allocated —
            subtract the fees chip from the flipped profit for the true bottom line.
        </p>
    @endif
</div>
