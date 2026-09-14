<div class="card" wire:poll.30s>
    <h2>Currently training</h2>

    @if ($current === null)
        <p class="muted">The skill queue is empty — the character is not training!</p>
    @elseif ($paused)
        <p style="margin:0">
            <b>{{ $current->name ?? 'Skill #'.$current->skill_id }}</b> → level {{ $current->finished_level }}
            — <span class="gold">queue paused</span>
        </p>
    @else
        <p style="margin:0; font-size: 16px;">
            <b>{{ $current->name ?? 'Skill #'.$current->skill_id }}</b> → level {{ $current->finished_level }}
            @if ($current->rank !== null)
                <span class="chip" style="margin-left:6px">rank {{ $current->rank }}</span>
                <span class="chip">{{ substr($current->primary_attribute, 0, 3) }}/{{ substr($current->secondary_attribute, 0, 3) }}</span>
            @endif
        </p>

        @if ($progress !== null)
            <div class="bar"><div style="width: {{ round($progress * 100) }}%"></div></div>
            <p class="muted num" style="margin:0">
                {{ round($progress * 100) }}%
                @if ($levelSpDone !== null)
                    · {{ number_format($levelSpDone) }} / {{ number_format($levelSpTotal) }} SP
                @endif
                · finishes {{ \Carbon\CarbonImmutable::parse($current->finish_date)->diffForHumans() }}
                @if ($spPerHour !== null)
                    · <span class="ok">{{ number_format($spPerHour) }} SP/h</span>
                @endif
            </p>
        @endif
    @endif
</div>
