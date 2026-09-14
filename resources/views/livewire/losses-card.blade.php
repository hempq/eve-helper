<div class="card wide" style="margin-top: 24px">
    <h2>Ship losses
        @if ($summary->available && $summary->losses->isNotEmpty())
            <span class="muted" style="text-transform:none; letter-spacing:0">
                — {{ $summary->count30d }} in 30d ({{ number_format($summary->value30d / 1_000_000, 1) }}M ISK)
                · {{ number_format($summary->totalValue / 1_000_000, 1) }}M ISK all listed
            </span>
        @endif
    </h2>

    @if (! $summary->available)
        <p class="muted" style="margin: 0">zKillboard is unreachable right now — try again in a few minutes.</p>
    @elseif ($summary->losses->isEmpty())
        <p class="muted" style="margin: 0">No recorded losses on zKillboard. Fly safe o7</p>
    @else
        <div class="tablewrap">
            <table>
                <tr><th>When</th><th>Ship</th><th>System</th><th class="r">Lost</th><th class="r">Dropped</th><th></th></tr>
                @foreach ($summary->losses as $loss)
                    <tr>
                        <td class="num muted">{{ $loss->time?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>{{ $loss->shipName ?? '—' }}</td>
                        <td>
                            @if ($loss->systemName)
                                {{ $loss->systemName }}
                                <span class="muted num" style="font-size:12px">{{ number_format($loss->security, 1) }}</span>
                            @else — @endif
                        </td>
                        <td class="r num bad">{{ number_format($loss->value / 1_000_000, 1) }}M</td>
                        <td class="r num">{{ $loss->droppedValue > 0 ? number_format($loss->droppedValue / 1_000_000, 1).'M' : '—' }}</td>
                        <td>
                            @if ($loss->npc) <span class="chip">NPC</span> @endif
                            @if ($loss->solo) <span class="chip gold">solo</span> @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="margin-bottom:0; font-size:12.5px">
            Source: zKillboard (public) + ESI killmail details.
            @if ($summary->truncated) The 30-day figures only cover the losses shown above. @endif
        </p>
    @endif
</div>
