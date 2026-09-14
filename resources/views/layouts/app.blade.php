<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'EVE Helper')</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <style>
        :root {
            --bg: #0b1017; --surface: #121926; --surface2: #182234; --line: #243044;
            --ink: #e2e8f2; --muted: #94a2ba; --accent: #41c3da; --accent-dim: #1b5666;
            --gold: #d9a648; --ok: #5cc28c; --bad: #e07d7d;
            --font-display: "Chakra Petch", system-ui, sans-serif;
            --font-body: "IBM Plex Sans", system-ui, sans-serif;
            --font-mono: "IBM Plex Mono", ui-monospace, monospace;
        }
        * { box-sizing: border-box; }
        body { font: 15px/1.6 var(--font-body); background: var(--bg); color: var(--ink); margin: 0; min-height: 100vh; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .topbar { border-bottom: 1px solid var(--line); background: var(--surface); }
        .topbar-inner { max-width: 1100px; margin: 0 auto; padding: 0 20px; display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
        .brand { font: 700 18px var(--font-display); color: var(--ink); letter-spacing: .04em; padding: 14px 0; }
        .brand span { color: var(--accent); }
        nav.main { display: flex; gap: 4px; }
        nav.main a { font: 600 13px var(--font-display); letter-spacing: .08em; text-transform: uppercase; color: var(--muted); padding: 18px 14px 15px; border-bottom: 3px solid transparent; }
        nav.main a:hover { color: var(--ink); text-decoration: none; }
        nav.main a.active { color: var(--accent); border-bottom-color: var(--accent); }
        .char-chip { margin-left: auto; display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: 13px; }
        .char-chip b { color: var(--ink); font-weight: 600; }
        .char-chip form { margin: 0; }
        .char-chip button { background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 4px 12px; cursor: pointer; font: 500 12px var(--font-body); }
        .char-chip button:hover { color: var(--ink); border-color: var(--muted); }

        main { max-width: 1100px; margin: 0 auto; padding: 24px 20px 60px; }
        h1 { font: 600 24px/1.3 var(--font-display); margin: 0 0 4px; }
        h2 { font: 600 15px var(--font-display); letter-spacing: .06em; text-transform: uppercase; color: var(--accent); margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 20px; }
        .muted { color: var(--muted); }
        .gold { color: var(--gold); }
        .ok { color: var(--ok); }
        .bad { color: var(--bad); }
        .num { font-variant-numeric: tabular-nums; }
        .mono { font-family: var(--font-mono); font-size: .93em; }

        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .tile { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; }
        .tile .label { font: 600 11px var(--font-display); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 4px; }
        .tile .value { font: 600 22px/1.2 var(--font-display); font-variant-numeric: tabular-nums; }
        .tile .hint { font-size: 12.5px; color: var(--muted); margin-top: 2px; }

        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 16px 18px; }
        .card.wide { grid-column: 1 / -1; }

        .bar { height: 7px; background: var(--line); border-radius: 4px; overflow: hidden; margin: 10px 0 6px; }
        .bar > div { height: 100%; background: linear-gradient(90deg, var(--accent-dim), var(--accent)); }

        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th { font: 600 11px var(--font-display); letter-spacing: .08em; text-transform: uppercase; text-align: left; color: var(--muted); border-bottom: 1px solid var(--line); padding: 7px 10px; }
        td { border-bottom: 1px solid color-mix(in srgb, var(--line) 55%, transparent); padding: 7px 10px; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: color-mix(in srgb, var(--surface2) 60%, transparent); }
        th.r, td.r { text-align: right; }
        .tablewrap { overflow-x: auto; }

        .chip { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: 2px 10px; font-size: 12.5px; color: var(--muted); white-space: nowrap; }
        .chip b { color: var(--ink); }
        .chip.up { border-color: var(--ok); color: var(--ok); }
        .chip.down { border-color: var(--bad); color: var(--bad); }
        .chip.gold { border-color: var(--gold); color: var(--gold); }

        .pips { display: inline-flex; gap: 3px; vertical-align: middle; }
        .pip { width: 9px; height: 9px; border: 1px solid var(--line); border-radius: 2px; }
        .pip.full { background: var(--accent); border-color: var(--accent); }
        .pip.partial { background: var(--accent-dim); border-color: var(--accent-dim); }

        .flash { border: 1px solid var(--bad); border-left-width: 4px; border-radius: 6px; padding: 10px 14px; color: var(--bad); margin-bottom: 16px; }

        footer { max-width: 1100px; margin: 0 auto; padding: 0 20px 30px; color: var(--muted); font-size: 12.5px; }
        @media (max-width: 640px) { .char-chip { margin-left: 0; padding-bottom: 10px; } }
    </style>
</head>
<body>
<div class="topbar">
    <div class="topbar-inner">
        <div class="brand">EVE<span>HELPER</span></div>
        @if (isset($character) && $character)
            <nav class="main">
                <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])>Dashboard</a>
                <a href="{{ route('skills') }}" @class(['active' => request()->routeIs('skills')])>Skills</a>
                <a href="{{ route('remap') }}" @class(['active' => request()->routeIs('remap')])>Remap</a>
            </nav>
            <div class="char-chip">
                <span><b>{{ $character->name }}</b> · <span class="num">{{ number_format($character->total_sp ?? 0) }} SP</span></span>
                <form method="POST" action="{{ route('eve.logout') }}">
                    @csrf
                    <button type="submit">Log out</button>
                </form>
            </div>
        @endif
    </div>
</div>

<main>
    @if (session('error'))
        <div class="flash">{{ session('error') }}</div>
    @endif
    @yield('content')
</main>

<footer>
    @if (isset($character) && $character)
        Last ESI sync: {{ $character->last_synced_at?->diffForHumans() ?? 'never' }} ·
    @endif
    EVE Helper — data from ESI &amp; SDE. Fly safe o7
</footer>
</body>
</html>
