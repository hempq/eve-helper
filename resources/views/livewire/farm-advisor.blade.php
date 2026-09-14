<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>Where to farm? <span class="muted" style="text-transform:none; letter-spacing:0">— from {{ $originName }} (your current location)</span></h2>
        <p class="muted" style="margin-top: 0; font-size: 13px">
            ESI does not expose anomalies — no tool can. Proxies do the job: NPC kills prove sites are being run
            and respawning; player kills &amp; traffic mean competition.
        </p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px;">range
                <select wire:model.live="maxJumps" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 6px 8px; font: inherit;">
                    @foreach ([5, 10, 15, 20] as $j)<option value="{{ $j }}">{{ $j }} jumps</option>@endforeach
                </select>
            </label>
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
            <label class="muted" style="display:flex; gap:6px; align-items:center; font-size:13px; cursor:pointer;">
                <input type="checkbox" wire:model.live="useWormholes"> Thera/Turnur shortcuts
            </label>
            <span wire:loading class="muted">Scanning…</span>
        </div>

        @if ($notice)
            <p class="gold" style="margin: 10px 0 0">{{ $notice }}</p>
        @endif
    </div>

    <div class="cards">
        <div class="card wide" style="grid-column: 1 / -1;">
            <h2>Candidate systems</h2>
            @if ($targets->isEmpty())
                <p class="muted" style="margin: 0">No systems match the filters in range — widen the range or relax the filters.</p>
            @else
                <div class="tablewrap">
                    <table>
                        <tr>
                            <th>System</th><th>Sec</th><th>Constellation</th><th>Region</th>
                            <th class="r">Jumps</th><th class="r">NPC kills/h</th><th class="r">Player kills/h</th>
                            <th class="r">Traffic</th><th class="r">Score</th><th></th>
                        </tr>
                        @foreach ($targets as $target)
                            <tr>
                                <td><b>{{ $target->name }}</b></td>
                                <td class="num" style="color: {{ $target->security >= 0.5 ? 'var(--ok)' : ($target->security > 0 ? 'var(--gold)' : 'var(--bad)') }}">{{ number_format($target->security, 1) }}</td>
                                <td class="muted">{{ $target->constellation }}</td>
                                <td class="muted">{{ $target->region }}</td>
                                <td class="r num">{{ $target->distance }}</td>
                                <td class="r num ok">{{ number_format($target->npcKills) }}</td>
                                <td class="r num {{ $target->playerKills > 0 ? 'bad' : 'muted' }}">{{ $target->playerKills }}</td>
                                <td class="r num muted">{{ number_format($target->traffic) }}</td>
                                <td class="r num"><b>{{ $target->score }}</b></td>
                                <td>
                                    <button wire:click="setDestination({{ $target->systemId }})" title="Set destination in game"
                                            style="background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 2px 9px; cursor: pointer; font: 500 12px var(--font-body);">➤</button>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        <div class="card">
            <h2>Ratting sessions <span class="muted" style="text-transform:none; letter-spacing:0">(bounty ticks, ~20 min each)</span></h2>
            @if ($sessions->isEmpty())
                <p class="muted" style="margin: 0">No bounty payouts in the wallet journal yet — go shoot some rats and this fills up.</p>
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
                    <div style="display: flex; gap: 3px; align-items: flex-end; height: 60px; margin-top: 14px;">
                        @foreach ($dailyTotals as $day => $isk)
                            <div title="{{ $day }}: {{ number_format($isk) }} ISK"
                                 style="flex: 1; background: var(--accent-dim); border-top: 2px solid var(--accent); height: {{ max(4, round($isk / $max * 100)) }}%;"></div>
                        @endforeach
                    </div>
                    <p class="muted" style="font-size: 11.5px; margin: 4px 0 0">Daily bounty income, last {{ $dailyTotals->count() }} active days.</p>
                @endif
            @endif
        </div>

        <div class="card">
            <h2>Thera / Turnur shortcuts <span class="muted" style="text-transform:none; letter-spacing:0">(EVE-Scout live)</span></h2>
            @if ($shortcuts->isEmpty())
                <p class="muted" style="margin: 0">EVE-Scout data unavailable right now.</p>
            @else
                <div class="tablewrap">
                    <table>
                        <tr><th>Hub</th><th>Connects to</th><th>Region</th><th class="r">Ship size</th><th class="r">Time left</th></tr>
                        @foreach ($shortcuts as $connection)
                            <tr>
                                <td>{{ $connection->hubName }}</td>
                                <td><b>{{ $connection->systemName }}</b></td>
                                <td class="muted">{{ $connection->region }}</td>
                                <td class="r muted">{{ $connection->maxShipSize ?? '—' }}</td>
                                <td class="r num {{ ($connection->remainingHours ?? 99) < 4 ? 'bad' : 'muted' }}">
                                    {{ $connection->remainingHours !== null ? round($connection->remainingHours).'h' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                <p class="muted" style="font-size: 11.5px; margin: 8px 0 0">Tick "Thera/Turnur shortcuts" above to include these as extra edges in the range scan.</p>
            @endif
        </div>
    </div>
</div>
