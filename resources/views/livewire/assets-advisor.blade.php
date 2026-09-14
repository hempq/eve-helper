<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>Sell trip planner</h2>
        <p class="muted" style="margin-top: 0">One load, multiple stops: every item sells where it nets the most — extra stops only when they pay for the jumps.</p>

        @if ($locations->isEmpty())
            <p class="muted" style="margin: 0">No assets synced yet — the next sync will pull them from ESI.</p>
        @else
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select wire:model.live="locationId"
                        style="min-width: 300px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 8px 10px; font: inherit;">
                    <option value="">Pick a stash… ({{ $locations->count() }} places)</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->location_id }}">
                            {{ $location->name }} — {{ $location->itemCount }} items, {{ count($location->typeQuantities) }} sellable types
                        </option>
                    @endforeach
                </select>

                <div style="display: flex; gap: 4px;">
                    @foreach (['order' => 'Sell orders', 'instant' => 'Instant'] as $value => $label)
                        <button wire:click="$set('mode', '{{ $value }}')"
                                style="padding: 8px 14px; border-radius: 6px; cursor: pointer; font: 600 12.5px var(--font-display);
                                       border: 1px solid {{ $mode === $value ? 'var(--accent)' : 'var(--line)' }};
                                       background: {{ $mode === $value ? 'var(--accent-dim)' : 'transparent' }};
                                       color: {{ $mode === $value ? 'var(--accent)' : 'var(--muted)' }};">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <label class="muted" style="display: flex; gap: 6px; align-items: center; font-size: 13px;">
                    a jump is worth
                    <select wire:model.live="iskPerJumpM"
                            style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                        @foreach ([0 => 'any gain', 1 => '1M ISK', 2 => '2M ISK', 5 => '5M ISK', 10 => '10M ISK', 25 => '25M ISK'] as $m => $label)
                            <option value="{{ $m }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <span wire:loading class="muted">Planning trip…</span>
            </div>
        @endif

        @if ($notice)
            <p class="gold" style="margin: 10px 0 0">{{ $notice }}</p>
        @endif
    </div>

    @if ($selected !== null && $selected->typeQuantities === [])
        <div class="card"><p class="muted" style="margin: 0">Nothing marketable at this location.</p></div>
    @endif

    @if ($plan !== null)
        <div class="tiles">
            <div class="tile">
                <div class="label">Trip total ({{ $plan->metric === 'instant' ? 'instant' : 'sell orders' }})</div>
                <div class="value ok">{{ number_format($plan->totalNet) }} ISK</div>
                <div class="hint">
                    {{ count($plan->stops) }} stop{{ count($plan->stops) === 1 ? '' : 's' }}
                    @if ($plan->totalJumps !== null) · {{ $plan->totalJumps }} jumps total @endif
                </div>
            </div>
            <div class="tile">
                <div class="label">vs single hub ({{ $plan->singleHubName }})</div>
                <div class="value gold">+{{ number_format($plan->extraOverSingleHub()) }} ISK</div>
                <div class="hint">
                    @if ($plan->extraJumps() !== null && $plan->extraJumps() > 0)
                        for {{ $plan->extraJumps() }} extra jumps
                    @elseif (count($plan->stops) === 1)
                        single stop is already optimal
                    @else
                        route length comparable
                    @endif
                </div>
            </div>
            <div class="tile">
                <div class="label">Itinerary</div>
                <div class="value" style="font-size: 17px">
                    {{ collect($plan->stops)->pluck('systemName')->implode(' → ') }}
                </div>
                <div class="hint">optimal visiting order</div>
            </div>
            <div class="tile">
                <div class="label">Autopilot</div>
                <div class="value" style="font-size: 15px; padding-top: 6px;">
                    <button wire:click="sendRoute({{ json_encode(collect($plan->stops)->pluck('stationId')->all()) }})"
                            style="background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 8px 16px; cursor: pointer; font: 600 13px var(--font-body);">
                        Send full trip to game
                    </button>
                </div>
            </div>
        </div>

        @foreach ($plan->stops as $index => $stop)
            <div class="card wide" style="margin-bottom: 14px">
                <h2>
                    Stop {{ $index + 1 }} — {{ $stop->systemName }}
                    <span class="muted" style="text-transform: none; letter-spacing: 0">
                        · {{ count($stop->items) }} types · net {{ number_format($stop->net) }} ISK
                        @if ($stop->jumpsFromPrevious !== null)
                            · {{ $stop->jumpsFromPrevious }} jumps from {{ $index === 0 ? 'your stash' : $plan->stops[$index - 1]->systemName }}
                        @endif
                    </span>
                    <button wire:click="sendRoute([{{ $stop->stationId }}])" title="Set only this stop as destination"
                            style="float: right; background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 3px 10px; cursor: pointer; font: 500 12px var(--font-body);">
                        Set destination
                    </button>
                </h2>

                @if (isset($legs[$index]) && count($legs[$index]) > 1)
                    <div style="display: flex; flex-wrap: wrap; gap: 5px; align-items: center; margin-bottom: 12px;">
                        @foreach ($legs[$index] as $i => $system)
                            @php $secColor = $system->security >= 0.5 ? 'var(--ok)' : ($system->security > 0 ? 'var(--gold)' : 'var(--bad)'); @endphp
                            <span class="chip" style="font-size: 11.5px; padding: 1px 8px; @if ($system->dangerous) border-color: var(--bad) @endif">
                                <span style="color: {{ $secColor }}" class="num">{{ number_format($system->security, 1) }}</span>
                                {{ $system->name }}
                                @if ($system->dangerous)
                                    <span class="bad" title="{{ $system->kills }} player kills in the last hour">⚠{{ $system->kills }}</span>
                                @endif
                            </span>
                            @if ($i < count($legs[$index]) - 1)<span class="muted" style="font-size: 11px">→</span>@endif
                        @endforeach
                    </div>
                    @if (collect($legs[$index])->contains('dangerous', true))
                        <p class="bad" style="font-size: 12.5px; margin: 0 0 10px">⚠ Kill activity on this leg in the last hour — possible gate camp on the flagged systems.</p>
                    @endif
                @endif

                <div class="tablewrap">
                    <table class="sortable">
                        <tr><th>Item</th><th class="r">Qty</th><th class="r">Net here</th></tr>
                        @foreach (array_slice($stop->items, 0, 12) as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td class="r num">{{ number_format($item->quantity) }}</td>
                                <td class="r num">{{ number_format($item->net) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                @if (count($stop->items) > 12)
                    <p class="muted" style="margin-bottom: 0; font-size: 12.5px">… and {{ count($stop->items) - 12 }} more types here.</p>
                @endif
            </div>
        @endforeach

        @if ($plan->unsellableNames !== [])
            <div class="card wide">
                <p class="muted" style="margin: 0; font-size: 12.5px">
                    <span class="gold">No market at any hub</span> (consider contracts):
                    {{ implode(' · ', array_slice($plan->unsellableNames, 0, 10)) }}{{ count($plan->unsellableNames) > 10 ? ' …' : '' }}
                </p>
            </div>
        @endif
    @endif
</div>
