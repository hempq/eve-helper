<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EVE Helper — {{ $character->name }}</title>
    <style>
        :root { --bg:#0b1017; --surface:#121926; --line:#243044; --ink:#e2e8f2; --muted:#94a2ba; --accent:#41c3da; --gold:#d9a648; --bad:#e07d7d; }
        * { box-sizing: border-box; }
        body { font: 15px/1.6 system-ui, sans-serif; background: var(--bg); color: var(--ink); margin: 0; padding: 24px 16px; }
        .wrap { max-width: 960px; margin: 0 auto; }
        header { display: flex; flex-wrap: wrap; align-items: baseline; gap: 12px 20px; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin: 0; }
        h2 { font-size: 1.05rem; margin: 0 0 10px; color: var(--accent); }
        .muted { color: var(--muted); }
        .error { color: var(--bad); }
        .num { font-variant-numeric: tabular-nums; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; }
        .stat { font-size: 1.5rem; font-weight: 600; }
        .attrs { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { border: 1px solid var(--line); border-radius: 999px; padding: 2px 10px; font-size: .85rem; color: var(--muted); }
        .chip b { color: var(--ink); }
        .bar { height: 6px; background: var(--line); border-radius: 3px; overflow: hidden; margin: 8px 0 4px; }
        .bar > div { height: 100%; background: var(--accent); }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th { text-align: left; color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--line); padding: 6px 10px; }
        td { border-bottom: 1px solid var(--line); padding: 6px 10px; }
        tr:last-child td { border-bottom: none; }
        form.logout { display: inline; margin-left: auto; }
        button { background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 4px 12px; cursor: pointer; }
        .tablewrap { overflow-x: auto; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>{{ $character->name }}</h1>
        <span class="muted num">{{ number_format($character->total_sp ?? 0) }} SP</span>
        @if (($character->unallocated_sp ?? 0) > 0)
            <span class="num" style="color: var(--gold)">{{ number_format($character->unallocated_sp) }} unallocated SP</span>
        @endif
        <form class="logout" method="POST" action="{{ route('eve.logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </header>

    @if ($syncError)
        <p class="error">Sync problem: {{ $syncError }}</p>
    @endif

    <div class="cards">
        <div class="card">
            <h2>Attributes</h2>
            <div class="attrs">
                <span class="chip">Int <b>{{ $character->intelligence }}</b></span>
                <span class="chip">Mem <b>{{ $character->memory }}</b></span>
                <span class="chip">Per <b>{{ $character->perception }}</b></span>
                <span class="chip">Wil <b>{{ $character->willpower }}</b></span>
                <span class="chip">Cha <b>{{ $character->charisma }}</b></span>
            </div>
            <p class="muted" style="margin-bottom:0">
                Bonus remaps: {{ $character->bonus_remaps ?? '?' }}
                @if ($character->last_remap_date)
                    · last remap {{ $character->last_remap_date->toDateString() }}
                @else
                    · never remapped
                @endif
            </p>
        </div>

        <div class="card">
            <h2>Currently training</h2>
            @if ($current === null)
                <p class="muted">The skill queue is empty.</p>
            @elseif ($queuePaused)
                <p><b>{{ $current->name ?? 'Skill #'.$current->skill_id }}</b> {{ $current->finished_level }} — <span style="color: var(--gold)">queue paused</span></p>
            @else
                <p style="margin:0"><b>{{ $current->name ?? 'Skill #'.$current->skill_id }}</b> → level {{ $current->finished_level }}</p>
                @if ($currentProgress !== null)
                    <div class="bar"><div style="width: {{ round($currentProgress * 100) }}%"></div></div>
                    <p class="muted num" style="margin:0">
                        {{ round($currentProgress * 100) }}% · finishes {{ \Carbon\CarbonImmutable::parse($current->finish_date)->diffForHumans() }}
                        @if ($currentSpPerHour !== null)
                            · {{ number_format($currentSpPerHour) }} SP/h
                        @endif
                    </p>
                @endif
            @endif
        </div>
    </div>

    <div class="card">
        <h2>Skill queue <span class="muted">({{ $queue->count() }} entries{{ $queueEndsAt ? ', ends '.\Carbon\CarbonImmutable::parse($queueEndsAt)->diffForHumans() : '' }})</span></h2>
        <div class="tablewrap">
        <table>
            <tr><th>#</th><th>Skill</th><th>To level</th><th>Finishes</th></tr>
            @foreach ($queue->take(15) as $entry)
                <tr>
                    <td class="num">{{ $entry->position + 1 }}</td>
                    <td>{{ $entry->name ?? 'Skill #'.$entry->skill_id }}</td>
                    <td class="num">{{ $entry->finished_level }}</td>
                    <td class="num muted">{{ $entry->finish_date ? \Carbon\CarbonImmutable::parse($entry->finish_date)->format('Y-m-d H:i') : '—' }}</td>
                </tr>
            @endforeach
        </table>
        </div>
        @if ($queue->count() > 15)
            <p class="muted" style="margin-bottom:0">… and {{ $queue->count() - 15 }} more.</p>
        @endif
    </div>

    <p class="muted">Last synced: {{ $character->last_synced_at?->diffForHumans() ?? 'never' }}</p>
</div>
</body>
</html>
