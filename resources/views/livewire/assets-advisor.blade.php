<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>My assets — where to sell?</h2>

        @if ($locations->isEmpty())
            <p class="muted" style="margin: 0">No assets synced yet — the next sync will pull them from ESI.</p>
        @else
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select wire:model.live="locationId"
                        style="min-width: 320px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 8px 10px; font: inherit;">
                    <option value="">Pick a location… ({{ $locations->count() }} places)</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->location_id }}">
                            {{ $location->name }} — {{ $location->itemCount }} items, {{ count($location->typeQuantities) }} sellable types
                        </option>
                    @endforeach
                </select>
                <span wire:loading class="muted">Pricing all hubs…</span>
            </div>

            @if ($selected !== null && $selected->typeQuantities === [])
                <p class="muted" style="margin: 10px 0 0">Nothing marketable at this location.</p>
            @endif
        @endif

        @if ($notice)
            <p class="gold" style="margin: 10px 0 0">{{ $notice }}</p>
        @endif
    </div>

    @if ($analysis !== null && $analysis['best'] !== null)
        <div class="tiles">
            <div class="tile">
                <div class="label">Best hub (sell orders)</div>
                <div class="value ok">{{ $analysis['best']->systemName }}</div>
                <div class="hint">net {{ number_format($analysis['best']->orderNet) }} ISK
                    @if ($analysis['best']->jumps !== null) · {{ $analysis['best']->jumps }} jumps (safer) @endif
                </div>
            </div>
            <div class="tile">
                <div class="label">Advantage over #2</div>
                @php $second = $analysis['hubs'][1] ?? null; @endphp
                <div class="value gold">{{ $second ? number_format($analysis['best']->orderNet - $second->orderNet) : '—' }} ISK</div>
                <div class="hint">{{ $second ? 'vs '.$second->systemName : '' }}</div>
            </div>
            <div class="tile">
                <div class="label">Instant sell there</div>
                <div class="value">{{ number_format($analysis['best']->instantNet) }} ISK</div>
                <div class="hint">if you can't wait for orders</div>
            </div>
            <div class="tile">
                <div class="label">Autopilot</div>
                <div class="value" style="font-size: 15px; padding-top: 6px;">
                    <button wire:click="setDestination({{ $analysis['best']->stationId }})"
                            style="background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 8px 16px; cursor: pointer; font: 600 13px var(--font-body);">
                        Set destination in game
                    </button>
                </div>
            </div>
        </div>

        <div class="cards">
            <div class="card">
                <h2>Hub comparison</h2>
                <table>
                    <tr><th>Hub</th><th class="r">Sell orders net</th><th class="r">Instant net</th><th class="r">Jumps</th></tr>
                    @foreach ($analysis['hubs'] as $hub)
                        <tr @if ($hub->stationId === $analysis['best']->stationId) style="color: var(--accent)" @endif>
                            <td>{{ $hub->systemName }}</td>
                            <td class="r num">{{ number_format($hub->orderNet) }}</td>
                            <td class="r num">{{ number_format($hub->instantNet) }}</td>
                            <td class="r num">{{ $hub->jumps ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
                <p class="muted" style="font-size: 12.5px; margin-bottom: 0">
                    Net = with your sales tax & broker fee. Jumps use the safer route (high-sec preferred).
                </p>
            </div>

            @if ($analysis['routeSystems'] !== null)
                <div class="card">
                    <h2>Route to {{ $analysis['best']->systemName }} <span class="muted">({{ count($analysis['routeSystems']) - 1 }} jumps)</span></h2>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                        @foreach ($analysis['routeSystems'] as $i => $system)
                            @php
                                $sec = $system->security;
                                $secColor = $sec >= 0.5 ? 'var(--ok)' : ($sec > 0 ? 'var(--gold)' : 'var(--bad)');
                            @endphp
                            <span class="chip" @if ($system->dangerous) style="border-color: var(--bad)" @endif>
                                <span style="color: {{ $secColor }}" class="num">{{ number_format($sec, 1) }}</span>
                                {{ $system->name }}
                                @if ($system->dangerous)
                                    <span class="bad" title="{{ $system->kills }} player kills in the last hour">⚠ {{ $system->kills }}</span>
                                @endif
                            </span>
                            @if ($i < count($analysis['routeSystems']) - 1)<span class="muted">→</span>@endif
                        @endforeach
                    </div>
                    @if (collect($analysis['routeSystems'])->contains('dangerous', true))
                        <p class="bad" style="font-size: 12.5px; margin-bottom: 0">
                            ⚠ Kill activity on this route in the last hour — watch the flagged systems (possible gate camp).
                        </p>
                    @endif
                </div>
            @endif
        </div>

        @if ($analysis['items'] !== null)
            <div class="card wide">
                <h2>Items @ {{ $analysis['best']->systemName }}</h2>
                <div class="tablewrap">
                    <table>
                        <tr>
                            <th>Item</th><th class="r">Qty</th>
                            <th class="r">Sell (5%)</th><th class="r">Order net</th><th>Advice</th>
                        </tr>
                        @foreach (array_slice($analysis['items']->items, 0, 25) as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td class="r num">{{ number_format($item->quantity) }}</td>
                                <td class="r num">{{ $item->sellPrice > 0 ? number_format($item->sellPrice, 2) : '—' }}</td>
                                <td class="r num">{{ number_format($item->orderNet) }}</td>
                                <td>
                                    @switch($item->recommendation())
                                        @case('sell-order') <span class="chip up">sell order</span> @break
                                        @case('instant') <span class="chip">instant</span> @break
                                        @default <span class="chip gold" title="No orders at this hub — try contracts">contract?</span>
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                @if (count($analysis['items']->items) > 25)
                    <p class="muted" style="margin-bottom: 0">… and {{ count($analysis['items']->items) - 25 }} more types.</p>
                @endif
            </div>
        @endif
    @endif
</div>
