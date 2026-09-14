<?php

namespace App\Http\Controllers;

use App\Models\Character;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TradeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $character = ($id = $request->session()->get('character_id')) !== null
            ? Character::find($id)
            : null;

        if ($character === null) {
            return redirect()->route('home');
        }

        return view('trade', ['character' => $character]);
    }
}
