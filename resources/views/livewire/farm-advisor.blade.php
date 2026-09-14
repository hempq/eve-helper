<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>Where to farm? <span class="muted" style="text-transform:none; letter-spacing:0">— you are in {{ $originName }}</span></h2>
        <p class="muted" style="margin-top: 0; font-size: 13px">
            ESI does not expose anomalies — no tool can. Score = spawn evidence (constellation NPC kills) × vacancy
            (few pilots/kills here) − danger.
            @if ($usingHistory)
                <span class="ok">Using 72h activity history.</span>
            @else
                <span class="gold">Using a single live snapshot — run <span class="mono">eve:record-activity</span> hourly for averages.</span>
            @endif
        </p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <div class="combo" style="min-width: 260px;">
                <input type="text" wire:model.live.debounce.300ms="regionSearch"
                       placeholder="Region — type a region or system name" autocomplete="off"
                       style="width: 100%; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 7px 11px; font: inherit; font-size: 13px;">
                @if ($regionResults->isNotEmpty())
                    <div class="dropdown">
                        @foreach ($regionResults as $result)
                            <button class="dd-item" wire:click="pickRegion({{ $result->region_id }})">
                                <b>{{ $result->name }}</b>
                                <span class="muted">{{ $result->via_system ? 'via '.$result->via_system : 'region' }}</span>
                            </button>
                        @endforeach
                    </div>
                @elseif ($searchOpen && mb_strlen(trim($regionSearch)) >= 2)
                    <div class="dropdown"><div class="dd-item" style="cursor: default; color: var(--muted)">No matching region.</div></div>
                @endif
            </div>

            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">space
                <select wire:model.live="securityBand" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                    <option value="any">any</option>
                    <option value="highsec">high-sec</option>
                    <option value="lowsec">low-sec</option>
                    <option value="nullsec">null-sec</option>
                </select>
            </label>
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">loot faction
                <select wire:model.live="faction" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                    <option value="">any</option>
                    @foreach ($factions as $f)<option value="{{ $f }}">{{ $f }}</option>@endforeach
                </select>
            </label>
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">tour size
                <input type="number" wire:model.live.debounce.400ms="tourSize" min="3" max="40"
                       style="width: 64px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;"> systems
            </label>
            <span wire:loading class="muted">Scoring region…</span>
        </div>

        @if ($notice)
            <p class="gold" style="margin: 10px 0 0">{{ $notice }}</p>
        @endif
    </div>

    @if ($regionName === null)
        <div class="card"><p class="muted" style="margin:0">Pick a region above to score it.</p></div>
    @elseif ($scored->isEmpty())
        <div class="card"><p class="muted" style="margin:0">No systems match the filters in {{ $regionName }}.</p></div>
    @else
        @if ($tour !== null)
            <div class="card wide" style="margin-bottom: 14px">
                <h2>Best {{ count($tour->systems) }} in {{ $regionName }}
                    <span class="muted" style="text-transform:none; letter-spacing:0">
                        — {{ $tour->totalJumps }} jumps total
                        @if ($tour->approachJumps !== null)({{ $tour->approachJumps }} to reach the first)@endif
                        @if ($tour->revisitCount > 0) · {{ $tour->revisitCount }} revisit{{ $tour->revisitCount === 1 ? '' : 's' }}@endif
                    </span>
                    <button wire:click="sendTour({{ json_encode(collect($tour->systems)->pluck('systemId')->all()) }})"
                            style="float: right; background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 5px 14px; cursor: pointer; font: 600 12.5px var(--font-body);">
                        Send tour to game
                    </button>
                </h2>

                <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 12px;">
                    @foreach ($tour->systems as $i => $system)
                        @php $secColor = $system->security >= 0.5 ? 'var(--ok)' : ($system->security > 0 ? 'var(--gold)' : 'var(--bad)'); @endphp
                        <span class="chip" @if ($system->playerKills > 0) style="border-color: var(--bad)" @endif>
                            @if ($system->legJumps !== null && $system->legJumps > 1)<span class="muted" style="font-size: 11px">+{{ $system->legJumps }}j</span>@endif
                            <span style="color: {{ $secColor }}" class="num">{{ number_format($system->security, 1) }}</span>
                            <b>{{ $system->name }}</b>
                            @if ($system->deadEnd)<span title="Dead-end" style="color: var(--accent)">◖</span>@endif
                        </span>
                        @if ($i < count($tour->systems) - 1)<span class="muted">→</span>@endif
                    @endforeach
                </div>

                @if (count($tour->fullPath) > 1)
                    <p class="muted" style="margin: 0 0 6px; font-size: 12.5px">
                        Full flight path — gate by gate ({{ $tour->totalJumps }} jumps):
                        <button wire:click="sendFullPath({{ json_encode(collect($tour->fullPath)->pluck('systemId')->all()) }})"
                                style="margin-left: 6px; background: none; border: 1px solid var(--accent); color: var(--accent); border-radius: 6px; padding: 2px 10px; cursor: pointer; font: 600 11.5px var(--font-body);">
                            Import exact path to game
                        </button>
                    </p>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                        @foreach ($tour->fullPath as $i => $hop)
                            @php $secColor = $hop->security >= 0.5 ? 'var(--ok)' : ($hop->security > 0 ? 'var(--gold)' : 'var(--bad)'); @endphp
                            <span class="chip" style="font-size: 11px; padding: 1px 8px; @if (! $hop->isTarget) opacity: .6; border-style: dashed; @endif">
                                <span style="color: {{ $secColor }}" class="num">{{ number_format($hop->security, 1) }}</span>
                                {{ $hop->name }}@if ($hop->revisit)<span class="gold" title="Passing through again"> ↩</span>@endif
                            </span>
                            @if ($i < count($tour->fullPath) - 1)<span class="muted" style="font-size: 10px">→</span>@endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="card wide">
            <h2>{{ $regionName }} — all {{ $scored->count() }} systems ranked</h2>
            <div class="tablewrap">
                <table>
                    <tr>
                        <th>System</th><th>Sec</th><th>Constellation</th>
                        <th class="r" title="Jumps from your location">Jumps</th>
                        <th class="r" title="NPC kills/h in this system (avg)">Sys NPC</th>
                        <th class="r" title="Constellation avg NPC kills/h — spawn evidence">Const NPC</th>
                        <th class="r" title="Gate traffic/h (avg)">Traffic</th>
                        <th class="r" title="Live player kills — danger">⚠</th>
                        <th class="r" title="Your logged sites in the constellation (30d)">Yours</th>
                        <th class="r">Score</th><th></th>
                    </tr>
                    @foreach ($scored->take(60) as $s)
                        <tr>
                            <td><b>{{ $s->name }}</b>
                                @if ($s->deadEnd)<span class="chip" style="border-color: var(--accent); color: var(--accent); font-size: 10.5px; padding: 0 6px;">dead-end</span>@endif
                                @if ($s->trend === 'backlog')<span class="chip gold" title="Usually ratted hard but quiet for the last half-day — uncleared sites are piling up" style="font-size: 10.5px; padding: 0 6px;">backlog</span>@endif
                                @if ($s->trend === 'surging')<span class="chip down" title="Activity well above this system's own baseline — someone is farming it right now" style="font-size: 10.5px; padding: 0 6px;">busy now</span>@endif
                            </td>
                            <td class="num" style="color: {{ $s->security >= 0.5 ? 'var(--ok)' : ($s->security > 0 ? 'var(--gold)' : 'var(--bad)') }}">{{ number_format($s->security, 1) }}</td>
                            <td class="muted">{{ $s->constellation }}</td>
                            <td class="r num">{{ $s->distance ?? '—' }}</td>
                            <td class="r num muted">{{ number_format($s->npcKills, 1) }}</td>
                            <td class="r num ok">{{ number_format($s->constellationNpcKills, 1) }}</td>
                            <td class="r num muted">{{ number_format($s->traffic, 1) }}</td>
                            <td class="r num {{ $s->liveDanger > 0 ? 'bad' : 'muted' }}">{{ $s->liveDanger ?: '—' }}</td>
                            <td class="r num {{ $s->ownSites > 0 ? 'gold' : 'muted' }}">{{ $s->ownSites ?: '—' }}</td>
                            <td class="r num"><b>{{ $s->score }}</b></td>
                            <td>
                                <button wire:click="setDestination({{ $s->systemId }})" title="Set destination"
                                        style="background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 2px 9px; cursor: pointer; font: 500 12px var(--font-body);">➤</button>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @if ($scored->count() > 60)
                <p class="muted" style="margin-bottom: 0">… and {{ $scored->count() - 60 }} more systems.</p>
            @endif
        </div>

        <div class="card" style="margin-top: 14px">
            <h2>Ratting sessions <span class="muted" style="text-transform:none; letter-spacing:0">(bounty ticks)</span></h2>
            @if ($sessions->isEmpty())
                <p class="muted" style="margin: 0">No bounty payouts logged yet.</p>
            @else
                <div class="tablewrap">
                    <table>
                        <tr><th>When</th><th>Systems</th><th class="r">Ticks</th><th class="r">ISK</th><th class="r">ISK/h</th></tr>
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="num">{{ $session->start->format('m-d H:i') }} <span class="muted">({{ $session->hours }}h)</span></td>
                                <td class="muted">{{ implode(', ', array_slice($session->systems, 0, 3)) }}</td>
                                <td class="r num">{{ $session->ticks }}</td>
                                <td class="r num">{{ number_format($session->isk) }}</td>
                                <td class="r num ok">{{ number_format($session->iskPerHour) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                @if ($dailyTotals->isNotEmpty())
                    @php $max = max(1, $dailyTotals->max()); @endphp
                    <div style="display: flex; gap: 3px; align-items: flex-end; height: 50px; margin-top: 12px;">
                        @foreach ($dailyTotals as $day => $isk)
                            <div title="{{ $day }}: {{ number_format($isk) }} ISK"
                                 style="flex: 1; background: var(--accent-dim); border-top: 2px solid var(--accent); height: {{ max(4, round($isk / $max * 100)) }}%;"></div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
