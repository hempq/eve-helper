<div class="card wide" style="margin-top: 24px">
    <h2>Daily earnings
        <span class="muted" style="text-transform:none; letter-spacing:0">— last
            <select wire:model.live="days" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 2px 6px; font: inherit; font-size: 13px;">
                <option value="7">7</option><option value="14">14</option><option value="30">30</option>
            </select> days
            · {{ number_format($series->total / 1_000_000, 1) }}M ISK in
        </span>
    </h2>

    @if ($series->total <= 0)
        <p class="muted" style="margin:0">No income recorded in this window.</p>
    @else
        @php
            $colors = ['Bounties' => 'var(--ok)', 'Missions' => 'var(--accent)', 'Market sales' => 'var(--gold)', 'Contracts' => '#b07fd9', 'Other' => 'var(--muted)'];
            $max = max(1.0, $series->days->max('total'));
            $n = $series->days->count();
            $barW = 100 / $n;
        @endphp
        <svg viewBox="0 0 100 46" style="width:100%; height:150px" preserveAspectRatio="none">
            @foreach ($series->days as $i => $day)
                @php $y = 44.0; @endphp
                @foreach ($series->buckets as $bucket)
                    @php
                        $value = $day->buckets[$bucket] ?? 0.0;
                        $h = $value / $max * 40;
                        $y -= $h;
                    @endphp
                    @if ($h > 0.01)
                        <rect x="{{ round($i * $barW + $barW * 0.12, 2) }}" y="{{ round($y, 2) }}"
                              width="{{ round($barW * 0.76, 2) }}" height="{{ round($h, 2) }}"
                              fill="{{ $colors[$bucket] ?? 'var(--muted)' }}">
                            <title>{{ $day->date }} · {{ $bucket }}: {{ number_format($value) }} ISK</title>
                        </rect>
                    @endif
                @endforeach
            @endforeach
        </svg>
        <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center; font-size:12.5px; margin-top:6px">
            @foreach ($series->buckets as $bucket)
                @php $sum = $series->days->sum(fn ($d) => $d->buckets[$bucket] ?? 0.0); @endphp
                @if ($sum > 0)
                    <span class="muted"><span style="display:inline-block; width:10px; height:10px; border-radius:2px; background: {{ $colors[$bucket] ?? 'var(--muted)' }}; vertical-align:-1px"></span>
                        {{ $bucket }} · {{ number_format($sum / 1_000_000, 1) }}M</span>
                @endif
            @endforeach
            <span class="muted" style="margin-left:auto">{{ $series->days->first()->date }} → {{ $series->days->last()->date }}</span>
        </div>
    @endif
</div>
