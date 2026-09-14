<div class="card wide" style="margin-top: 24px">
    <h2>LP store — best value <span class="muted" style="text-transform:none; letter-spacing:0">(ISK per loyalty point, valued at Jita)</span></h2>

    @if ($needsScope)
        <p class="muted" style="margin:0">Needs the loyalty scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($offers->isEmpty())
        <p class="muted" style="margin:0">No profitable LP offers found (or you have no loyalty points).</p>
    @else
        <div class="tablewrap">
            <table>
                <tr>
                    <th>Item</th><th>Corp</th>
                    <th class="r">LP</th><th class="r">ISK cost</th>
                    <th class="r" title="Jita 5% percentile sell / buy order price">Jita sell / buy</th>
                    <th class="r" title="Net proceeds of a sell order (your tax + broker fee) minus all costs">Profit</th>
                    <th class="r" title="Net ISK per LP listing a sell order (tax + broker fee)">ISK/LP sell</th>
                    <th class="r" title="Net ISK per LP dumping into buy orders right now (tax only)">ISK/LP instant</th>
                    <th class="r" title="Units traded per day at Jita — a great-looking offer that never trades is a trap">Vol/day</th>
                    <th></th>
                </tr>
                @foreach ($offers as $o)
                    <tr>
                        <td>{{ $o->quantity > 1 ? $o->quantity.'× ' : '' }}{{ $o->item }}</td>
                        <td class="muted">{{ $o->corp }}</td>
                        <td class="r num">{{ number_format($o->lpCost) }}</td>
                        <td class="r num">{{ number_format($o->iskCost) }}</td>
                        <td class="r num">{{ number_format($o->sellPrice) }} <span class="muted">/ {{ number_format($o->buyPrice) }}</span></td>
                        <td class="r num {{ $o->profit > 0 ? 'ok' : 'bad' }}">{{ number_format($o->profit) }}</td>
                        <td class="r num gold">{{ number_format($o->iskPerLp, 1) }}</td>
                        <td class="r num {{ $o->iskPerLpInstant > 0 ? '' : 'muted' }}">{{ number_format($o->iskPerLpInstant, 1) }}</td>
                        <td class="r num">
                            @if ($o->dailyVolume === null)
                                <span class="muted">—</span>
                            @else
                                {{ number_format($o->dailyVolume, 1) }}
                            @endif
                        </td>
                        <td style="white-space: nowrap">
                            @if ($o->dailyVolume !== null && $o->dailyVolume < 1)
                                <span class="chip down" title="Barely trades — the sell price is theoretical; trust the instant column">slow</span>
                            @elseif ($o->dailyVolume !== null && $o->quantity > $o->dailyVolume)
                                <span class="chip gold" title="Selling one batch takes ~{{ round($o->quantity / max(0.01, $o->dailyVolume)) }} days at the daily volume">{{ round($o->quantity / max(0.01, $o->dailyVolume)) }}d</span>
                            @endif
                            @if ($o->affordable)<span class="chip up" title="You have enough LP">ok</span>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="font-size: 12.5px; margin-bottom: 0">
            Net of your sales tax and broker fee. "ISK/LP sell" lists at the Jita sell price and waits;
            "instant" dumps into buy orders now. Low-volume items: believe the instant number, not the sell one.
        </p>
    @endif
</div>
