<?php

namespace App\Http\Controllers;

use App\Models\Character;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class SettingsController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $character = ($id = $request->session()->get('character_id')) !== null
            ? Character::find($id)
            : null;

        if ($character === null) {
            return redirect()->route('home');
        }

        return view('settings', [
            'character' => $character,
            'characters' => Character::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $character = Character::findOrFail($request->session()->get('character_id'));

        $validated = $request->validate([
            'route_security' => 'required|in:highsec,highlow,all',
        ]);

        $character->forceFill($validated)->save();

        return redirect()->route('settings')->with('status', 'Settings saved.');
    }
}
