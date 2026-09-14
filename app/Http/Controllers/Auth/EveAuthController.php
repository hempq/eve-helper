<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\Sso\EveSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EveAuthController extends Controller
{
    public function redirect(Request $request, EveSsoService $sso): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('eve_sso_state', $state);

        return redirect()->away($sso->authorizeUrl($state, route('eve.callback')));
    }

    public function callback(Request $request, EveSsoService $sso): RedirectResponse
    {
        $expectedState = $request->session()->pull('eve_sso_state');
        abort_if($expectedState === null || $request->query('state') !== $expectedState, 403, 'Invalid SSO state.');

        if ($request->query('code') === null) {
            return redirect()->route('home')->with('error', 'EVE login was cancelled or failed.');
        }

        $tokens = $sso->exchangeCode($request->query('code'));
        $claims = $sso->claims($tokens->accessToken);

        $character = Character::updateOrCreate(
            ['character_id' => $claims->characterId],
            [
                'name' => $claims->name,
                'owner_hash' => $claims->ownerHash,
                'scopes' => $claims->scopes,
                'access_token' => $tokens->accessToken,
                'access_token_expires_at' => $tokens->expiresAt,
                'refresh_token' => $tokens->refreshToken,
            ],
        );

        $request->session()->put('character_id', $character->character_id);

        return redirect()->route('home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('character_id');

        return redirect()->route('home');
    }
}
