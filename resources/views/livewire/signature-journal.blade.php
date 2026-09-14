<div class="cards" style="margin-top: 14px">
    <div class="card">
        <h2>Signature journal</h2>
        <p class="muted" style="margin-top: 0; font-size: 13px">
            In game: probe scanner window → select all (Ctrl+A) → copy (Ctrl+C) → paste here.
            The list for your <b>current system</b> updates: new, rescanned and despawned sites are tracked.
        </p>

        <textarea wire:model="paste" rows="4" placeholder="VOB-799	Cosmic Signature	Data Site	Local Serpentis Mainframe	100.0%	7.03 AU"
                  style="width: 100%; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 8px 10px; font: 12px/1.5 var(--font-mono); resize: vertical;"></textarea>

        <div style="display: flex; gap: 10px; margin-top: 8px; flex-wrap: wrap; align-items: center;">
            <button wire:click="ingest"
                    style="background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 8px 18px; cursor: pointer; font: 600 13px var(--font-body);">
                Log signatures
            </button>

            <input type="text" wire:model="escalationName" placeholder="Escalation name (e.g. Serpentis Phi-Outpost)"
                   style="flex: 1; min-width: 200px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 7px 10px; font: inherit; font-size: 13px;">
            <button wire:click="addEscalation" title="Logs an escalation in your current system with a 24h timer"
                    style="background: none; border: 1px solid var(--gold); color: var(--gold); border-radius: 6px; padding: 7px 14px; cursor: pointer; font: 600 12.5px var(--font-body);">
                + Escalation (24h)
            </button>
        </div>

        @if ($notice)
            <p class="gold" style="margin: 10px 0 0">{{ $notice }}</p>
        @endif

        @if ($stats->isNotEmpty())
            <h2 style="margin-top: 18px">What spawns for you <span class="muted">(30 days, best yield first)</span></h2>
            <table class="sortable">
                <tr>
                    <th>Constellation</th><th class="r">Sites</th><th class="r">Combat</th>
                    <th class="r" title="Escalations gained here">Esc</th><th class="r">Done</th>
                    <th class="r" title="Sites logged per day actually spent in this constellation — your personal yield">Sites/day</th>
                    <th class="r">Last visit</th>
                </tr>
                @foreach ($stats as $stat)
                    <tr>
                        <td>{{ $stat->constellation }}</td>
                        <td class="r num">{{ $stat->total }}</td>
                        <td class="r num">{{ $stat->combat }}</td>
                        <td class="r num {{ $stat->escalations > 0 ? 'gold' : 'muted' }}">{{ $stat->escalations ?: '—' }}</td>
                        <td class="r num ok">{{ $stat->done }}</td>
                        <td class="r num"><b>{{ number_format($stat->total / max(1, $stat->visit_days), 1) }}</b></td>
                        <td class="r num muted">{{ \Carbon\CarbonImmutable::parse($stat->last_visit)->diffForHumans(short: true) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card">
        <h2>Active sites &amp; escalations <span class="muted">({{ $active->count() }})</span></h2>

        @if ($active->isEmpty())
            <p class="muted" style="margin: 0">Nothing logged yet — paste a probe scan or add an escalation.</p>
        @else
            <div class="tablewrap">
                <table class="sortable">
                    <tr><th>ID</th><th>System</th><th>Type</th><th>Name</th><th class="r">Scan</th><th class="r">Expires</th><th></th></tr>
                    @foreach ($active as $sig)
                        <tr>
                            <td class="mono">{{ $sig->sig_id }}</td>
                            <td>{{ $sig->system_name }}</td>
                            <td>
                                @if ($sig->sig_group === 'Escalation')
                                    <span class="chip gold">escalation</span>
                                @else
                                    {{ $sig->category ?? '?' }}
                                @endif
                            </td>
                            <td class="muted">{{ $sig->name ?? '—' }}</td>
                            <td class="r num">{{ $sig->signal !== null ? number_format($sig->signal, 0).'%' : '—' }}</td>
                            <td class="r num {{ $sig->expires_at && \Carbon\CarbonImmutable::parse($sig->expires_at)->diffInHours(now(), true) > 18 ? 'bad' : 'muted' }}">
                                {{ $sig->expires_at ? \Carbon\CarbonImmutable::parse($sig->expires_at)->diffForHumans() : '—' }}
                            </td>
                            <td style="white-space: nowrap">
                                <button wire:click="setDestination({{ $sig->system_id }})" title="Set destination in the EVE client"
                                        style="background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 1px 8px; cursor: pointer; font: 500 12px var(--font-body);">➤</button>
                                <button wire:click="markDone({{ $sig->id }})" title="Mark completed"
                                        style="background: none; border: 1px solid var(--ok); color: var(--ok); border-radius: 6px; padding: 1px 8px; cursor: pointer; font: 600 12px var(--font-body);">✓</button>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
</div>
