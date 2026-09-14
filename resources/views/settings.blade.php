@extends('layouts.app')

@section('title', 'Settings — '.$character->name)

@section('content')
    <h1>Settings</h1>
    <p class="sub">Per-character preferences for {{ $character->name }}.</p>

    @if (session('status'))
        <div class="card" style="border-left: 3px solid var(--ok); margin-bottom: 14px"><p style="margin:0" class="ok">{{ session('status') }}</p></div>
    @endif

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf
        <div class="card" style="margin-bottom: 14px">
            <h2>Routing safety</h2>
            <p class="muted" style="margin-top: 0; font-size: 13px">Applies to every route, sell trip, hub comparison, trade and farm scan.</p>
            @foreach (\App\Models\Character::ROUTE_SECURITY_LABELS as $value => $label)
                <label style="display: flex; gap: 10px; align-items: baseline; padding: 6px 0; cursor: pointer;">
                    <input type="radio" name="route_security" value="{{ $value }}" @checked(($character->route_security ?? 'highsec') === $value)>
                    <span>
                        <b>{{ $label }}</b>
                        <span class="muted" style="font-size: 12.5px">
                            @switch($value)
                                @case('highsec') — never leaves 0.5+ space @break
                                @case('highlow') — may use low-sec, never null-sec @break
                                @case('all') — may cross null-sec (safer routes still preferred) @break
                            @endswitch
                        </span>
                    </span>
                </label>
            @endforeach
            <h2 style="margin-top: 18px">Hazard avoidance</h2>
            <label style="display: flex; gap: 10px; align-items: baseline; padding: 6px 0; cursor: pointer;">
                <input type="checkbox" name="hazard_avoidance" value="1" @checked($character->hazard_avoidance ?? true)>
                <span>
                    <b>Detour around live hazards</b>
                    <span class="muted" style="font-size: 12.5px">
                        — routes avoid incursion constellations, contested FW systems and systems with recent
                        player kills when a reasonable detour exists (soft penalties, never longer than the hazard is worth).
                    </span>
                </span>
            </label>

            <button type="submit" style="margin-top: 10px; background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 8px 20px; cursor: pointer; font: 600 13px var(--font-body);">Save</button>
        </div>
    </form>

    <div class="card" style="margin-bottom: 14px">
        <h2>Linked characters</h2>
        <table>
            <tr><th>Character</th><th class="r">SP</th><th class="r">Last sync</th><th></th></tr>
            @foreach ($characters as $c)
                <tr>
                    <td><b>{{ $c->name }}</b> @if ($c->character_id === $character->character_id)<span class="chip" style="border-color: var(--accent); color: var(--accent); font-size: 10.5px">active</span>@endif</td>
                    <td class="r num">{{ number_format($c->total_sp ?? 0) }}</td>
                    <td class="r num muted">{{ $c->last_synced_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="r">
                        @if ($c->character_id !== $character->character_id)
                            <form method="POST" action="{{ route('character.activate', $c->character_id) }}">
                                @csrf
                                <button type="submit" style="background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 2px 10px; cursor: pointer; font: 500 12px var(--font-body);">Switch to</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        <p class="muted" style="font-size: 12.5px; margin-bottom: 0"><a href="{{ route('eve.login') }}">+ Add another character</a> via EVE SSO.</p>
    </div>

    <div class="card">
        <h2>Data</h2>
        <p class="muted" style="margin: 0; font-size: 13px">
            Background sync every 15 min · farm activity snapshot hourly · hub scan twice daily (when the workers run).
            This character last synced {{ $character->last_synced_at?->diffForHumans() ?? 'never' }}.
        </p>
    </div>
@endsection
