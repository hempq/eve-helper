@extends('layouts.app')

@section('title', 'Remap optimizer — '.$character->name)

@section('content')
    <h1>Neural remap optimizer</h1>
    <p class="sub">The EVEMon method: every valid attribute distribution (14,641 candidates) simulated against your live skill queue.</p>

    @if ($report === null)
        <div class="card">
            <p class="muted" style="margin:0">The skill queue is empty (or has no SDE-matched skills) — add skills to the in-game queue first, then refresh.</p>
        </div>
    @else
        <div class="tiles">
            <div class="tile">
                <div class="label">Queue with current map</div>
                <div class="value">{{ \App\Support\Duration::minutes($report->currentMinutes) }}</div>
                <div class="hint">{{ number_format($report->totalSp) }} SP · {{ $report->skillCount }} queue entries</div>
            </div>
            <div class="tile">
                <div class="label">With optimal remap</div>
                <div class="value ok">{{ \App\Support\Duration::minutes($report->optimalMinutes) }}</div>
                <div class="hint">same queue, remapped attributes</div>
            </div>
            <div class="tile">
                <div class="label">Time saved</div>
                <div class="value gold">{{ \App\Support\Duration::minutes($report->savedMinutes()) }}</div>
                <div class="hint">{{ $report->currentMinutes > 0 ? round($report->savedMinutes() / $report->currentMinutes * 100, 1) : 0 }}% faster</div>
            </div>
            <div class="tile">
                <div class="label">Can you remap now?</div>
                <div class="value {{ $report->canRemapNow() ? 'ok' : 'bad' }}">{{ $report->canRemapNow() ? 'Yes' : 'No' }}</div>
                <div class="hint">
                    {{ $report->bonusRemaps }} bonus remap{{ $report->bonusRemaps === 1 ? '' : 's' }}
                    @if ($report->nextYearlyRemapAt)
                        · yearly {{ $report->nextYearlyRemapAt->isPast() ? 'available' : 'on '.$report->nextYearlyRemapAt->toDateString() }}
                    @else
                        · yearly remap available
                    @endif
                </div>
            </div>
        </div>

        <div class="cards">
            <div class="card">
                <h2>Recommended attribute map</h2>
                <table>
                    <tr>
                        <th>Attribute</th><th class="r">Current base</th><th class="r">Optimal base</th>
                        <th class="r">Change</th><th class="r">Effective*</th>
                    </tr>
                    @foreach (['perception', 'willpower', 'intelligence', 'memory', 'charisma'] as $attr)
                        @php
                            $cur = $report->currentBase->get($attr);
                            $opt = $report->optimalBase->get($attr);
                            $delta = $opt - $cur;
                        @endphp
                        <tr>
                            <td style="text-transform: capitalize">{{ $attr }}</td>
                            <td class="r num">{{ $cur }}</td>
                            <td class="r num"><b>{{ $opt }}</b></td>
                            <td class="r num">
                                @if ($delta > 0)
                                    <span class="ok">+{{ $delta }}</span>
                                @elseif ($delta < 0)
                                    <span class="bad">{{ $delta }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td class="r num">{{ $opt + $report->implantBonuses->get($attr) }}</td>
                        </tr>
                    @endforeach
                </table>
                <p class="muted" style="margin-bottom:0; font-size:12.5px">
                    * with your current implants ({{ collect($report->implantBonuses->toArray())->filter()->map(fn ($v, $k) => '+'.$v.' '.substr($k, 0, 3))->implode(', ') ?: 'none' }}).
                    Set these values in-game: Character Sheet → Character → Attributes → Remap.
                </p>
            </div>

            <div class="card">
                <h2>Queue composition by attribute pair</h2>
                <table>
                    <tr><th>Primary / Secondary</th><th class="r">SP</th><th class="r">Share</th></tr>
                    @foreach (collect($report->buckets)->sortByDesc('sp') as $bucket)
                        <tr>
                            <td style="text-transform: capitalize">{{ $bucket->primaryAttribute }} / {{ $bucket->secondaryAttribute }}</td>
                            <td class="r num">{{ number_format($bucket->sp) }}</td>
                            <td class="r num">{{ round($bucket->sp / $report->totalSp * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </table>
                <p class="muted" style="margin-bottom:0; font-size:12.5px">
                    A remap is most valuable when one pair dominates. Diversify the queue after remapping and the gain shrinks —
                    re-check this page whenever you rebuild the queue.
                </p>
            </div>
        </div>

        @if ($multi !== null && $multi->savedMinutes() >= 1440)
            <div class="card wide" style="margin-top: 24px">
                <h2>Multi-remap plan
                    <span class="muted" style="text-transform:none; letter-spacing:0">
                        — {{ $multi->remapCount() }} remaps save {{ \App\Support\Duration::minutes($multi->savedMinutes()) }}
                        vs the single remap above
                    </span>
                </h2>
                <div class="tablewrap">
                    <table>
                        <tr>
                            <th>#</th><th>Starts</th><th>Dominant</th><th class="r">SP</th><th class="r">Duration</th>
                            <th class="r">Per</th><th class="r">Wil</th><th class="r">Int</th><th class="r">Mem</th><th class="r">Cha</th>
                        </tr>
                        @foreach ($multi->segments as $i => $segment)
                            <tr>
                                <td class="num muted">{{ $i + 1 }}</td>
                                <td class="num">{{ $i === 0 ? 'now' : 'day '.round($segment->startMinutes / 1440) }}</td>
                                <td style="text-transform: capitalize">{{ $segment->dominantPrimary }}</td>
                                <td class="r num">{{ number_format($segment->sp) }}</td>
                                <td class="r num">{{ \App\Support\Duration::minutes($segment->minutes) }}</td>
                                <td class="r num"><b>{{ $segment->base->perception }}</b></td>
                                <td class="r num"><b>{{ $segment->base->willpower }}</b></td>
                                <td class="r num"><b>{{ $segment->base->intelligence }}</b></td>
                                <td class="r num"><b>{{ $segment->base->memory }}</b></td>
                                <td class="r num"><b>{{ $segment->base->charisma }}</b></td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                <p class="muted" style="margin-bottom:0; font-size:12.5px">
                    Each segment start is one remap (base attributes shown, implants come on top). Remaps are yearly + banked
                    bonus remaps — you have {{ $report->bonusRemaps }} bonus remap{{ $report->bonusRemaps === 1 ? '' : 's' }},
                    so check the segment dates fit your remap budget before committing.
                </p>
            </div>
        @endif
    @endif
@endsection
