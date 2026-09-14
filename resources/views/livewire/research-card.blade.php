<div class="card wide" style="margin-top: 24px">
    <h2>R&amp;D agents — datacore income
        @if (! $summary->needsScope && $summary->agents->isNotEmpty())
            <span class="muted" style="text-transform:none; letter-spacing:0">
                — {{ number_format($summary->totalValue / 1_000_000, 1) }}M ISK banked
            </span>
        @endif
    </h2>

    @if ($summary->needsScope)
        <p class="muted" style="margin:0">Needs the agents-research scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($summary->agents->isEmpty())
        <p class="muted" style="margin:0">No R&D agents researching for you. Train a science skill and start one — it pays passively forever.</p>
    @else
        <div class="tablewrap">
            <table class="sortable">
                <tr>
                    <th>Science</th><th>Agent</th><th>Station</th>
                    <th class="r">RP/day</th><th class="r">Datacores</th><th class="r">Value</th>
                    <th class="r" title="Passive income at the current datacore price">ISK/day</th>
                </tr>
                @foreach ($summary->agents as $agent)
                    <tr>
                        <td>{{ $agent->science }}</td>
                        <td>{{ $agent->level !== null ? 'L'.$agent->level : '' }} {{ $agent->corp ?? '' }}</td>
                        <td class="muted" style="font-size:12.5px">{{ $agent->station ?? '—' }}</td>
                        <td class="r num">{{ number_format($agent->pointsPerDay, 1) }}</td>
                        <td class="r num"><b>{{ number_format($agent->datacores) }}</b></td>
                        <td class="r num gold">{{ number_format($agent->value) }}</td>
                        <td class="r num">{{ number_format($agent->iskPerDay) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">1 datacore = 100 RP, valued at Jita buy. Visit the agent to buy the datacores out.</p>
    @endif
</div>
