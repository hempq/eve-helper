<div class="card">
    <h2>Mining ledger
        @if (! $summary->needsScope && $summary->ores->isNotEmpty())
            <span class="muted" style="text-transform:none; letter-spacing:0">— {{ number_format($summary->totalValue / 1_000_000, 1) }}M / {{ $summary->days }}d</span>
        @endif
    </h2>

    @if ($summary->needsScope)
        <p class="muted" style="margin:0">Needs the mining scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($summary->ores->isEmpty())
        <p class="muted" style="margin:0">Nothing mined in the last {{ $summary->days }} days.</p>
    @else
        <table class="sortable">
            <tr><th>Ore</th><th class="r">Units</th><th class="r">Value (Jita buy)</th></tr>
            @foreach ($summary->ores->take(10) as $ore)
                <tr>
                    <td>{{ $ore->name }}</td>
                    <td class="r num">{{ number_format($ore->quantity) }}</td>
                    <td class="r num">{{ number_format($ore->value) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
