@extends('layouts.app')

@section('title', 'Warzone — '.$character->name)

@section('content')
    <h1>Warzone</h1>
    <p class="sub">Live incursions and faction warfare — zones to avoid while farming, or to visit for LP. Distances from {{ $originName }}.</p>

    <div class="card wide">
        <h2>Incursions
            <span class="muted" style="text-transform:none; letter-spacing:0">— Sansha invasions; sites and rats are replaced constellation-wide</span>
        </h2>
        @if ($incursions === [])
            <p class="muted" style="margin:0">No active incursions (or ESI is unavailable).</p>
        @else
            <div class="tablewrap">
                <table class="sortable">
                    <tr>
                        <th>Constellation</th><th>Region</th><th>Staging</th>
                        <th class="r" title="Jumps from your location to the staging system">Jumps</th>
                        <th>State</th>
                        <th class="r" title="Sansha influence — at 100% system effects are strongest">Influence</th>
                        <th class="r">Systems</th><th></th>
                    </tr>
                    @foreach ($incursions as $inc)
                        <tr>
                            <td><b>{{ $inc->constellation }}</b></td>
                            <td class="muted">{{ $inc->region }}</td>
                            <td>{{ $inc->staging }}
                                @if ($inc->stagingSecurity !== null)
                                    <span class="num muted" style="font-size:12px">{{ number_format($inc->stagingSecurity, 1) }}</span>
                                @endif
                            </td>
                            <td class="r num">{{ $inc->distance ?? '—' }}</td>
                            <td>
                                <span class="chip {{ $inc->state === 'withdrawing' ? 'up' : ($inc->state === 'established' ? 'down' : 'gold') }}">{{ $inc->state }}</span>
                            </td>
                            <td class="r num">{{ round($inc->influence * 100) }}%</td>
                            <td class="r num">{{ $inc->systemCount }}</td>
                            <td>@if ($inc->hasBoss)<span class="chip gold" title="Kundalini Manifest spawned — the incursion can be finished">boss</span>@endif</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <p class="muted" style="font-size:12.5px; margin-bottom:0">
                Inside an incursion constellation normal anomalies are replaced and NPCs get buffs — skip these when farming.
                "Withdrawing" ends within 24h.
            </p>
        @endif
    </div>

    <div class="cards" style="margin-top: 24px">
        <div class="card">
            <h2>FW occupancy</h2>
            @if ($fw->summary === [])
                <p class="muted" style="margin:0">Faction warfare data unavailable.</p>
            @else
                <table class="sortable">
                    <tr><th>Faction</th><th class="r">Systems held</th><th class="r">Contested</th></tr>
                    @foreach (collect($fw->summary)->sortByDesc('systems') as $row)
                        <tr>
                            <td>{{ $row['faction'] }}</td>
                            <td class="r num">{{ $row['systems'] }}</td>
                            <td class="r num {{ $row['contested'] > 0 ? 'gold' : 'muted' }}">{{ $row['contested'] }}</td>
                        </tr>
                    @endforeach
                </table>
                <p class="muted" style="font-size:12.5px; margin-bottom:0">
                    FW plexing in contested systems earns LP for the militia — pairs with the LP-store calculator on the market page.
                </p>
            @endif
        </div>

        <div class="card">
            <h2>Nearest contested systems</h2>
            @if ($fw->contested === [])
                <p class="muted" style="margin:0">No contested systems right now.</p>
            @else
                <div class="tablewrap">
                    <table class="sortable">
                        <tr>
                            <th>System</th><th>Sec</th><th class="r">Jumps</th>
                            <th>Occupier</th><th class="r" title="Victory points toward flipping the system">Contest</th>
                        </tr>
                        @foreach (collect($fw->contested)->take(15) as $s)
                            <tr>
                                <td><b>{{ $s->name }}</b>
                                    @if ($s->state === 'vulnerable')<span class="chip down" style="font-size:10.5px; padding:0 6px" title="The infrastructure hub can be attacked — expect heavy fighting">vulnerable</span>@endif
                                </td>
                                <td class="num" style="color: {{ ($s->security ?? 0) >= 0.5 ? 'var(--ok)' : (($s->security ?? 0) > 0 ? 'var(--gold)' : 'var(--bad)') }}">{{ $s->security !== null ? number_format($s->security, 1) : '—' }}</td>
                                <td class="r num">{{ $s->distance ?? '—' }}</td>
                                <td class="muted">{{ $s->occupier }}</td>
                                <td class="r num">{{ number_format($s->contestedPct, 1) }}%</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                <p class="muted" style="font-size:12.5px; margin-bottom:0">
                    Active warzone systems mean gate camps and roams — steer the farm route around them.
                </p>
            @endif
        </div>
    </div>
@endsection
