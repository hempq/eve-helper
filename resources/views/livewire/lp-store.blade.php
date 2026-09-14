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
                    <th class="r">LP</th><th class="r">ISK cost</th><th class="r">Market value</th>
                    <th class="r">Profit</th><th class="r">ISK/LP</th><th></th>
                </tr>
                @foreach ($offers as $o)
                    <tr>
                        <td>{{ $o->quantity > 1 ? $o->quantity.'× ' : '' }}{{ $o->item }}</td>
                        <td class="muted">{{ $o->corp }}</td>
                        <td class="r num">{{ number_format($o->lpCost) }}</td>
                        <td class="r num">{{ number_format($o->iskCost) }}</td>
                        <td class="r num">{{ number_format($o->marketValue) }}</td>
                        <td class="r num {{ $o->profit > 0 ? 'ok' : 'bad' }}">{{ number_format($o->profit) }}</td>
                        <td class="r num gold">{{ number_format($o->iskPerLp, 1) }}</td>
                        <td>@if ($o->affordable)<span class="chip up" title="You have enough LP">ok</span>@endif</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="font-size: 12.5px; margin-bottom: 0">Market value = Jita sell (5%); a real sale pays tax and takes time. Best offers first.</p>
    @endif
</div>
