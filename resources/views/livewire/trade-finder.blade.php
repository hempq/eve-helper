<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>Trade route finder</h2>
        <p class="muted" style="margin-top: 0; font-size: 13px">
            Buy from sell orders at A, dump into buy orders at B — instant flip, capped by order depth, cargo and budget.
            Prices come from the last full hub scan
            @if ($scannedAt)
                (<b>{{ \Carbon\CarbonImmutable::parse($scannedAt)->diffForHumans() }}</b>).
            @else
                — <span class="bad">no scan yet: run <span class="mono">ddev artisan eve:scan-hubs</span> first.</span>
            @endif
        </p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">from
                <select wire:model.live="fromStation" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                    <option value="0">any hub</option>
                    @foreach ($hubs as $id => $hub)<option value="{{ $id }}">{{ $hub['system'] }}</option>@endforeach
                </select>
            </label>
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">to
                <select wire:model.live="toStation" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                    <option value="0">any hub</option>
                    @foreach ($hubs as $id => $hub)<option value="{{ $id }}">{{ $hub['system'] }}</option>@endforeach
                </select>
            </label>
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">cargo
                <input type="number" wire:model.live.debounce.500ms="cargo" min="100" max="400000" step="100"
                       style="width: 90px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;"> m³
            </label>
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">budget
                <input type="number" wire:model.live.debounce.500ms="budgetM" min="1" max="1000000" step="10"
                       style="width: 90px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;"> M ISK
            </label>
            <span wire:loading class="muted">Crunching…</span>
        </div>

        @if ($notice)
            <p class="gold" style="margin: 10px 0 0">{{ $notice }}</p>
        @endif
    </div>

    @if ($scannedAt && $trades->isEmpty())
        <div class="card"><p class="muted" style="margin:0">No profitable flips found with these constraints — widen the budget/cargo or wait for the next scan.</p></div>
    @elseif ($trades->isNotEmpty())
        <div class="card wide">
            <h2>Best flips <span class="muted">(profit is net of your sales tax)</span></h2>
            <div class="tablewrap">
                <table class="sortable">
                    <tr>
                        <th>Item</th><th>Route</th><th class="r">Buy @</th><th class="r">Sell @</th>
                        <th class="r">Qty</th><th class="r">Invest</th><th class="r">Profit</th>
                        <th class="r">Margin</th><th class="r">ISK/jump</th><th></th>
                    </tr>
                    @foreach ($trades as $trade)
                        <tr>
                            <td><b>{{ $trade->name }}</b> <span class="muted" style="font-size:11.5px">{{ number_format($trade->cargo) }} m³</span></td>
                            <td class="muted">{{ $trade->from }} → {{ $trade->to }}
                                @if ($trade->jumps !== null)<span style="font-size:11.5px">({{ $trade->jumps }}j)</span>@endif
                            </td>
                            <td class="r num">{{ number_format($trade->buyPrice, 2) }}</td>
                            <td class="r num">{{ number_format($trade->sellPrice, 2) }}</td>
                            <td class="r num">{{ number_format($trade->quantity) }}</td>
                            <td class="r num">{{ number_format($trade->invest) }}</td>
                            <td class="r num ok">{{ number_format($trade->profit) }}</td>
                            <td class="r num">{{ number_format($trade->margin * 100, 1) }}%</td>
                            <td class="r num gold">{{ $trade->iskPerJump !== null ? number_format($trade->iskPerJump) : '—' }}</td>
                            <td>
                                <button wire:click="setRoute({{ $trade->fromStationId }}, {{ $trade->toStationId }})" title="Set buy + sell waypoints"
                                        style="background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 2px 9px; cursor: pointer; font: 500 12px var(--font-body);">➤</button>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <p class="muted" style="font-size: 12px; margin-bottom: 0">
                ⚠ Depth-capped estimates from a point-in-time scan — prices move; re-check in game before committing big ISK.
            </p>
        </div>
    @endif
</div>
