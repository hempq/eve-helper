<?php

namespace App\Http\Controllers;

use App\Models\Character;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SkillsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $character = ($id = $request->session()->get('character_id')) !== null
            ? Character::find($id)
            : null;

        if ($character === null) {
            return redirect()->route('home');
        }

        $skills = DB::table('character_skills as cs')
            ->join('item_types as t', 't.type_id', '=', 'cs.skill_id')
            ->leftJoin('item_groups as g', 'g.group_id', '=', 't.group_id')
            ->leftJoin('skill_types as s', 's.type_id', '=', 'cs.skill_id')
            ->where('cs.character_id', $character->character_id)
            ->orderBy('t.name')
            ->select('cs.trained_level', 'cs.active_level', 'cs.skillpoints',
                't.name', 's.rank', 's.primary_attribute', 's.secondary_attribute',
                DB::raw("COALESCE(g.name, 'Other') as group_name"))
            ->get();

        $groups = $skills->groupBy('group_name')
            ->map(fn ($items, $name) => (object) [
                'name' => $name,
                'skills' => $items,
                'sp' => $items->sum('skillpoints'),
                'atFive' => $items->where('trained_level', 5)->count(),
            ])
            ->sortByDesc('sp')
            ->values();

        return view('skills', [
            'character' => $character,
            'groups' => $groups,
            'totalSkills' => $skills->count(),
        ]);
    }
}
