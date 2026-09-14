<div class="card wide" style="margin-top: 24px">
    <h2>Station trading
        <span class="muted" style="text-transform:none; letter-spacing:0">— 0-jump flips: buy order at the bid, relist at the ask, pocket the spread</span>
    </h2>

    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-bottom:12px">
        <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">hub
            <select wire:model.live="stationId" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                @foreach ($hubs as $id => $hub)<option value="{{ $id }}">{{ $hub['system'] }}</option>@endforeach
            </select>
        </label>
        <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">min margin
            <input type="number" wire:model.live.debounce.400ms="minMarginPct" min="2" max="50"
                   style="width: 60px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;"> %
        </label>
        <span wire:loading class="muted">Scanning…</span>
        @if ($scannedAt)
            <span class="muted" style="margin-left:auto; font-size:12.5px">order book scanned {{ \Carbon\CarbonImmutable::parse($scannedAt)->diffForHumans() }}</span>
        @endif
    </div>

    @if ($scannedAt === null)
        <p class="muted" style="margin:0">No order-book scan yet — run <span class="mono">eve:scan-hubs</span> (scheduled every 4 hours).</p>
    @elseif ($trades->isEmpty())
        <p class="muted" style="margin:0">No flips above the margin threshold at this hub right now.</p>
    @else
        <div class="tablewrap">
            <table class="sortable">
                <tr>
                    <th>Item</th>
                    <th class="r" title="Best buy order — place yours 0.01 above">Bid</th>
                    <th class="r" title="Best sell order — relist 0.01 below">Ask</th>
                    <th class="r" title="Per unit, after broker fees on both orders and sales tax">Profit/u</th>
                    <th class="r">Margin</th>
                    <th class="r" title="Units traded per day in the region">Vol/day</th>
                    <th class="r" title="Rough daily potential capturing ~10% of the flow">ISK/day</th>
                </tr>
                @foreach ($trades as $trade)
                    <tr>
                        <td>{{ $trade->name }}</td>
                        <td class="r num">{{ number_format($trade->bid, 2) }}</td>
                        <td class="r num">{{ number_format($trade->ask, 2) }}</td>
                        <td class="r num ok">{{ number_format($trade->profitPerUnit, 2) }}</td>
                        <td class="r num gold">{{ number_format($trade->margin * 100, 1) }}%</td>
                        <td class="r num">{{ $trade->dailyVolume !== null ? number_format($trade->dailyVolume, 1) : '—' }}</td>
                        <td class="r num"><b>{{ $trade->dailyPotential !== null ? number_format($trade->dailyPotential) : '—' }}</b></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">
            Uses your real broker fee ({{ number_format(app(\App\Services\Market\TradeFeeService::class)->brokerFeeRate($character, $stationId) * 100, 2) }}% here)
            and sales tax. Expect 0.01-ISK wars on popular items — the Vol/day column tells you where flips actually happen.
        </p>
    @endif
</div>
