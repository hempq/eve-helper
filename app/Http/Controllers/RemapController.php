<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\Skills\QueueAnalysisService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RemapController extends Controller
{
    public function show(Request $request, QueueAnalysisService $analysis): View|RedirectResponse
    {
        $character = ($id = $request->session()->get('character_id')) !== null
            ? Character::find($id)
            : null;

        if ($character === null) {
            return redirect()->route('home');
        }

        return view('remap', [
            'character' => $character,
            'report' => $analysis->remapReport($character),
            'multi' => $analysis->multiRemapPlan($character),
        ]);
    }
}
