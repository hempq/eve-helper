<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>Appraise your loot</h2>
        <p class="muted" style="margin-top: 0">Paste from the inventory (Ctrl+A, Ctrl+C in a hangar/cargo window), a contract, or one item per line.</p>

        <textarea wire:model="paste" rows="6" placeholder="Federation Navy Antimatter Charge M	1000
Damaged Artificial Neural Network	42
Gistii B-Type Small Shield Booster"
                  style="width: 100%; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 10px 12px; font: 13px/1.5 var(--font-mono); resize: vertical;"></textarea>

        <div style="display: flex; gap: 10px; margin-top: 10px; align-items: center; flex-wrap: wrap;">
            <select wire:model.live="stationId"
                    style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 8px 10px; font: inherit;">
                @foreach ($hubs as $id => $hub)
                    <option value="{{ $id }}">{{ $hub['system'] }}</option>
                @endforeach
            </select>
            <button wire:click="appraise" wire:loading.attr="disabled"
                    style="background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 9px 22px; cursor: pointer; font: 600 14px var(--font-body);">
                <span wire:loading.remove wire:target="appraise">Appraise</span>
                <span wire:loading wire:target="appraise">Pricing…</span>
            </button>
            @if ($result)
                <span class="muted" style="font-size: 12.5px">
                    Your rates: sales tax <b class="num">{{ number_format($result->salesTaxRate * 100, 2) }}%</b>,
                    broker fee <b class="num">{{ number_format($result->brokerFeeRate * 100, 2) }}%</b> (NPC station, with your standings toward its owner)
                </span>
            @endif
        </div>

        @if ($error)
            <p class="bad" style="margin: 10px 0 0">{{ $error }}</p>
        @endif
    </div>

    @if ($result)
        <div class="tiles">
            <div class="tile">
                <div class="label">Instant sell (net)</div>
                <div class="value">{{ number_format($result->totalInstantNet()) }} ISK</div>
                <div class="hint">hit buy orders, only sales tax</div>
            </div>
            <div class="tile">
                <div class="label">Sell orders (net)</div>
                <div class="value ok">{{ number_format($result->totalOrderNet()) }} ISK</div>
                <div class="hint">after sales tax + broker fee, takes time to fill</div>
            </div>
            <div class="tile">
                <div class="label">Difference</div>
                <div class="value gold">{{ number_format($result->totalOrderNet() - $result->totalInstantNet()) }} ISK</div>
                <div class="hint">patience premium</div>
            </div>
            <div class="tile">
                <div class="label">Cargo volume</div>
                <div class="value">{{ number_format($result->totalVolume(), 1) }} m³</div>
                <div class="hint">{{ count($result->items) }} item types</div>
            </div>
        </div>

        <div class="card wide">
            <h2>Items @ {{ $hubs[$result->stationId]['system'] ?? $result->stationId }}</h2>
            <div class="tablewrap">
                <table class="sortable">
                    <tr>
                        <th>Item</th><th class="r">Qty</th>
                        <th class="r">Buy (5%)</th><th class="r">Sell (5%)</th>
                        <th class="r">Instant net</th><th class="r">Order net</th>
                        <th class="r" title="Days for a sell order to fill: (units already listed at this hub + yours) / daily volume">Fill time</th><th>Advice</th>
                    </tr>
                    @foreach ($result->items as $item)
                        <tr>
                            <td>{{ $item->name }}</td>
                            <td class="r num">{{ number_format($item->quantity) }}</td>
                            <td class="r num">{{ $item->buyPrice > 0 ? number_format($item->buyPrice, 2) : '—' }}</td>
                            <td class="r num">{{ $item->sellPrice > 0 ? number_format($item->sellPrice, 2) : '—' }}</td>
                            <td class="r num">{{ number_format($item->instantNet) }}</td>
                            <td class="r num">{{ number_format($item->orderNet) }}</td>
                            <td class="r num muted">
                                @php $days = $item->daysToSell(); @endphp
                                @if ($days === null) — @elseif ($days < 1) &lt;1d @else {{ round($days) }}d @endif
                            </td>
                            <td>
                                @switch($item->recommendation())
                                    @case('sell-order') <span class="chip up">sell order</span> @break
                                    @case('instant') <span class="chip">instant</span> @break
                                    @case('contract')
                                        @if ($item->contractPrice !== null)
                                            <span class="chip gold" title="Barely trades on the market ({{ number_format($item->avgDailyVolume, 1) }}/day) — sell via contract. {{ $item->contractPrice->sampleCount }} public contracts listed: competitive ask (20th pct) {{ number_format($item->contractPrice->p20Price) }}, median {{ number_format($item->contractPrice->medianPrice) }} ISK">contract ~{{ $item->contractPrice->p20Price >= 1_000_000 ? number_format($item->contractPrice->p20Price / 1_000_000, 1).'M' : number_format($item->contractPrice->p20Price) }}</span>
                                        @else
                                            <span class="chip gold" title="Barely trades on the market ({{ number_format($item->avgDailyVolume, 1) }}/day) — sell via contract">contract</span>
                                        @endif
                                    @break
                                    @default <span class="chip gold" title="No orders at this hub — check contracts / other hubs">no market</span>
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @if ($result->unknownNames !== [])
                <p class="bad" style="font-size: 12.5px; margin-bottom: 0">
                    Unknown items (not in SDE): {{ implode(' · ', $result->unknownNames) }}
                </p>
            @endif
            @if ($result->unparsedLines !== [])
                <p class="muted" style="font-size: 12.5px; margin-bottom: 0">
                    Skipped lines: {{ implode(' · ', array_slice($result->unparsedLines, 0, 5)) }}{{ count($result->unparsedLines) > 5 ? ' …' : '' }}
                </p>
            @endif
        </div>
    @endif
</div>
