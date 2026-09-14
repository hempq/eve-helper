<div class="card wide" style="margin-top: 24px">
    <h2>Fitting replacement costs
        <span class="muted" style="text-transform:none; letter-spacing:0">— what buying each saved fit back would cost at Jita right now</span>
    </h2>

    @if ($result->needsScope)
        <p class="muted" style="margin:0">Needs the fittings scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($result->fittings->isEmpty())
        <p class="muted" style="margin:0">No saved fittings (save one in the in-game fitting tool).</p>
    @else
        <div class="tablewrap">
            <table class="sortable">
                <tr>
                    <th>Fitting</th><th>Hull</th>
                    <th class="r">Hull cost</th><th class="r">Modules</th><th class="r">Total</th>
                </tr>
                @foreach ($result->fittings->take(20) as $fit)
                    <tr>
                        <td>{{ $fit->name }}</td>
                        <td class="muted">{{ $fit->ship }}</td>
                        <td class="r num">{{ number_format($fit->hullCost) }}</td>
                        <td class="r num">{{ number_format($fit->fittingsCost) }}
                            @if ($fit->unpriced > 0)<span class="gold" title="{{ $fit->unpriced }} module(s) had no Jita price (deadspace/abyssal) — real cost is higher">*</span>@endif
                        </td>
                        <td class="r num"><b>{{ number_format($fit->total) }}</b></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">Jita sell prices. Undock only what you can afford to lose twice o7</p>
    @endif
</div>
