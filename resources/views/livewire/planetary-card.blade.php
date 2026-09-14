<div class="card">
    <h2>PI colonies</h2>

    @if ($result->needsScope)
        <p class="muted" style="margin:0">Needs the planets scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($result->colonies->isEmpty())
        <p class="muted" style="margin:0">No planetary colonies. PI is low-effort passive ISK — worth a look.</p>
    @else
        <table class="sortable">
            <tr><th>Planet</th><th class="r">Lvl</th><th class="r">Pins</th><th class="r">Extractors</th></tr>
            @foreach ($result->colonies as $colony)
                <tr>
                    <td>{{ $colony->system }} <span class="muted" style="font-size:12px">{{ $colony->planetType }}</span></td>
                    <td class="r num">{{ $colony->upgradeLevel }}</td>
                    <td class="r num muted">{{ $colony->pins }}</td>
                    <td class="r num">
                        @if ($colony->extractorExpiry === null)
                            <span class="muted">—</span>
                        @elseif ($colony->expired)
                            <span class="bad">stopped</span>
                        @else
                            <span class="{{ $colony->extractorExpiry->lt(now()->addHours(12)) ? 'gold' : 'ok' }}">{{ $colony->extractorExpiry->diffForHumans(short: true) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">The bell warns when an extraction program stops.</p>
    @endif
</div>
