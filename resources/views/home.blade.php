<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EVE Helper</title>
    <style>
        body { font: 16px/1.6 system-ui, sans-serif; background: #0b1017; color: #e2e8f2; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        main { text-align: center; padding: 2rem; }
        h1 { font-size: 2rem; margin-bottom: .5rem; }
        a.button, button { display: inline-block; background: #41c3da; color: #0b1017; font-weight: 600; border: 0; border-radius: 6px; padding: .7rem 1.4rem; text-decoration: none; cursor: pointer; font-size: 1rem; }
        .muted { color: #94a2ba; }
        .error { color: #e07d7d; }
    </style>
</head>
<body>
<main>
    <h1>EVE Helper</h1>

    @if (session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    @if ($character)
        <p>Logged in as <strong>{{ $character->name }}</strong> <span class="muted">({{ $character->character_id }})</span></p>
        <form method="POST" action="{{ route('eve.logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    @else
        <p class="muted">Sign in to sync your character data.</p>
        <a class="button" href="{{ route('eve.login') }}">Log in with EVE Online</a>
    @endif
</main>
</body>
</html>
