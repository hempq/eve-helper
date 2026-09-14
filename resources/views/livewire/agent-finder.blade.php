<div class="card wide">
    <h2>Agent finder
        <span class="muted" style="text-transform:none; letter-spacing:0">— nearby NPC agents you can actually use, from {{ $originName }}</span>
    </h2>

    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-bottom:12px">
        <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">min level
            <select wire:model.live="minLevel" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                @foreach ([1, 2, 3, 4, 5] as $l)<option value="{{ $l }}">L{{ $l }}</option>@endforeach
            </select>
        </label>
        <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">division
            <select wire:model.live="divisionId" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                <option value="">any</option>
                @foreach ($divisions as $d)<option value="{{ $d->division_id }}">{{ $d->name }}</option>@endforeach
            </select>
        </label>
        <span wire:loading class="muted">Searching…</span>
    </div>

    @if (! $hasStandings)
        <p class="muted" style="font-size:12.5px">No synced standings yet — <a href="{{ route('eve.login') }}">re-log in</a> to grant the standings scope; until then only L1 agents show as available.</p>
    @endif

    @if ($agents->isEmpty())
        <p class="muted" style="margin:0">No matching agents within 15 jumps (on your current routing safety).</p>
    @else
        <div class="tablewrap">
            <table>
                <tr>
                    <th>Level</th><th>Division</th><th>Corporation</th><th>Station</th><th>System</th>
                    <th class="r">Jumps</th>
                    <th class="r" title="Your best effective standing toward agent / corp / faction (Connections included)">Standing</th>
                    <th class="r">Needs</th><th></th>
                </tr>
                @foreach ($agents as $a)
                    <tr>
                        <td class="num"><b>L{{ $a->level }}</b></td>
                        <td>{{ $a->division }}
                            @if ($a->isResearch)<span class="chip" style="font-size:10.5px; padding:0 6px">R&amp;D</span>@endif
                            @if ($a->isLocator)<span class="chip gold" style="font-size:10.5px; padding:0 6px" title="Can locate other pilots">locator</span>@endif
                        </td>
                        <td>{{ $a->corp }} @if ($a->faction)<span class="muted" style="font-size:12px">· {{ $a->faction }}</span>@endif</td>
                        <td class="muted" style="font-size:12.5px">{{ $a->station }}</td>
                        <td>{{ $a->system }}
                            <span class="num" style="font-size:12px; color: {{ $a->security >= 0.5 ? 'var(--ok)' : ($a->security > 0 ? 'var(--gold)' : 'var(--bad)') }}">{{ number_format($a->security, 1) }}</span>
                        </td>
                        <td class="r num">{{ $a->distance ?? '—' }}</td>
                        <td class="r num {{ $a->available ? 'ok' : 'bad' }}">{{ number_format($a->standing, 2) }}</td>
                        <td class="r num muted">{{ number_format($a->required, 1) }}</td>
                        <td>
                            @if ($a->available)
                                <span class="chip up">available</span>
                            @else
                                <span class="chip" title="Raise standing with the corp or faction (missions, tags) to unlock">locked</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">
            Access needs the highest of your agent / corporation / faction standing (Connections included) at or above
            the level requirement (L2: 1.0 · L3: 3.0 · L4: 5.0 · L5: 7.0). Range: 15 jumps on your routing safety.
        </p>
    @endif
</div>
